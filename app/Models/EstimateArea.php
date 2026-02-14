<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateArea extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateAreaFactory> */
    use HasFactory;

    public const SCOPE_INTERIOR = 'interior';

    public const SCOPE_EXTERIOR = 'exterior';

    public const APPLICATION_BRUSH_ROLL = 'brush_roll';

    public const APPLICATION_SPRAY = 'spray';

    public const MEASUREMENT_DIMENSIONS = 'dimensions';

    public const MEASUREMENT_SQFT = 'sqft';

    public const MEASUREMENT_LNFT = 'lnft';

    protected $fillable = [
        'estimate_id',
        'parent_area_id',
        'nested_area_template_id',
        'substrate_id',
        'name',
        'scope_type',
        'length',
        'width',
        'height',
        'wall_sqft',
        'ceiling_sqft',
        'floor_sqft',
        'perimeter_lnft',
        'baseboard_lnft',
        'application_method',
        'is_fixed_price',
        'fixed_price_amount',
        'estimated_labor_hours',
        'estimated_material_cost',
        'estimated_labor_cost',
        'notes',
        'customer_notes',
        'customer_checklist',
        'agent_notes',
        'agent_checklist',
        'sort_order',
        'default_paint_product_id',
        'measurement_type',
        'wall_count',
        'direct_sqft',
        'direct_lnft',
        'direct_ceiling_sqft',
        'service_category_id',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'wall_sqft' => 'decimal:2',
            'ceiling_sqft' => 'decimal:2',
            'floor_sqft' => 'decimal:2',
            'perimeter_lnft' => 'decimal:2',
            'baseboard_lnft' => 'decimal:2',
            'is_fixed_price' => 'boolean',
            'fixed_price_amount' => 'decimal:2',
            'estimated_labor_hours' => 'decimal:2',
            'estimated_material_cost' => 'decimal:2',
            'estimated_labor_cost' => 'decimal:2',
            'customer_checklist' => 'array',
            'agent_checklist' => 'array',
            'sort_order' => 'integer',
            'wall_count' => 'integer',
            'direct_sqft' => 'decimal:2',
            'direct_lnft' => 'decimal:2',
            'direct_ceiling_sqft' => 'decimal:2',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateArea $area) {
            // Fixed price areas: populate labor breakdown from template, then skip dimension calc
            if ($area->is_fixed_price) {
                $area->populateLaborFromTemplate();

                return;
            }

            // Branch on measurement type BEFORE any dimension calculation
            $measurementType = $area->measurement_type ?? self::MEASUREMENT_DIMENSIONS;

            switch ($measurementType) {
                case self::MEASUREMENT_SQFT:
                    $area->wall_sqft = $area->direct_sqft ?? 0;
                    $area->ceiling_sqft = $area->direct_ceiling_sqft ?? 0;
                    $area->floor_sqft = $area->direct_ceiling_sqft ?? 0;
                    if ($area->height > 0 && $area->wall_sqft > 0) {
                        $area->perimeter_lnft = round($area->wall_sqft / $area->height, 2);
                    }
                    $area->baseboard_lnft = $area->perimeter_lnft;
                    break;

                case self::MEASUREMENT_LNFT:
                    $area->perimeter_lnft = $area->direct_lnft ?? 0;
                    $area->baseboard_lnft = $area->perimeter_lnft;
                    if ($area->height > 0) {
                        $area->wall_sqft = round($area->perimeter_lnft * $area->height, 2);
                    }
                    $area->ceiling_sqft = $area->direct_ceiling_sqft ?? 0;
                    $area->floor_sqft = $area->direct_ceiling_sqft ?? 0;
                    break;

                case self::MEASUREMENT_DIMENSIONS:
                default:
                    // Existing behavior + wall_count support
                    $wallCount = max(1, min(4, $area->wall_count ?? 4));

                    if ($area->length && $area->width && $area->height) {
                        $fullPerimeter = 2 * ($area->length + $area->width);
                        $ratio = $wallCount / 4;
                        $area->perimeter_lnft = round($fullPerimeter * $ratio, 2);
                        $area->wall_sqft = round($area->perimeter_lnft * $area->height, 2);
                        $area->ceiling_sqft = $area->length * $area->width;
                        $area->floor_sqft = $area->length * $area->width;

                        if (! $area->baseboard_lnft) {
                            $area->baseboard_lnft = $area->perimeter_lnft;
                        }
                    } elseif ($area->width && $area->height) {
                        // Single-wall mode (length=null) - existing behavior, unchanged
                        $area->wall_sqft = $area->width * $area->height;
                        $area->perimeter_lnft = $area->width;
                        $area->ceiling_sqft = 0;
                        $area->floor_sqft = 0;
                    }
                    break;
            }
        });

        // Trigger estimate and package recalculation when an area is saved
        // Skip recalculation if the estimate was just updated (it already has correct values from TemplateBuilder)
        static::saved(function (EstimateArea $area) {
            // Only recalculate if the estimate wasn't recently updated (within last second)
            // This prevents the save flow from double-calculating
            if ($area->estimate && $area->estimate->updated_at && $area->estimate->updated_at->lt(now()->subSecond())) {
                $area->estimate->calculateTotals();
                $area->estimate->calculateDepositAndBalance();
                $area->estimate->save();

                // Recalculate package totals since areas affect package pricing via effective_price
                foreach ($area->estimate->packages as $package) {
                    $package->calculateTotals();
                    $package->save();
                }
            }
        });

        // Trigger estimate and package recalculation when an area is deleted
        static::deleted(function (EstimateArea $area) {
            if ($area->estimate && $area->estimate->exists) {
                $area->estimate->calculateTotals();
                $area->estimate->calculateDepositAndBalance();
                $area->estimate->save();

                // Recalculate package totals since areas affect package pricing
                foreach ($area->estimate->packages as $package) {
                    $package->calculateTotals();
                    $package->save();
                }
            }
        });
    }

    /**
     * Get the estimate for this area.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the parent area (for nested areas like closets).
     */
    public function parentArea(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class, 'parent_area_id');
    }

    /**
     * Get child/nested areas (e.g., closets attached to a bedroom).
     */
    public function childAreas(): HasMany
    {
        return $this->hasMany(EstimateArea::class, 'parent_area_id')->orderBy('sort_order');
    }

    /**
     * Get the nested area template (if this is a templated nested area).
     */
    public function nestedAreaTemplate(): BelongsTo
    {
        return $this->belongsTo(NestedAreaTemplate::class);
    }

    /**
     * Get the substrate for this area.
     */
    public function substrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class);
    }

    /**
     * Get the service category for this area.
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * Get the default paint product for this area (overrides estimate default).
     */
    public function defaultPaintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class, 'default_paint_product_id');
    }

    /**
     * Get the effective paint product for this area.
     * Cascades: Area default → Estimate default → null
     */
    public function getEffectivePaintProduct(): ?PaintProduct
    {
        return $this->defaultPaintProduct ?? $this->estimate?->defaultPaintProduct;
    }

    /**
     * Get the surfaces for this area.
     */
    public function surfaces(): HasMany
    {
        return $this->hasMany(EstimateAreaSurface::class, 'area_id')->orderBy('sort_order');
    }

    /**
     * Get paint selections for this area.
     */
    public function paintSelections(): HasMany
    {
        return $this->hasMany(EstimateAreaPaintSelection::class);
    }

    /**
     * Get addons (nested area templates) for this area.
     */
    public function addons(): HasMany
    {
        return $this->hasMany(EstimateAreaAddon::class);
    }

    /**
     * Get the total of all included surfaces.
     */
    public function getSurfacesTotalAttribute(): float
    {
        return (float) $this->surfaces()
            ->where('is_included', true)
            ->sum('total');
    }

    /**
     * Get the total fixed price including nested areas.
     */
    public function getTotalFixedPriceAttribute(): float
    {
        $total = $this->is_fixed_price ? (float) $this->fixed_price_amount : 0;

        // Add fixed prices from nested areas
        foreach ($this->childAreas as $child) {
            if ($child->is_fixed_price) {
                $total += (float) $child->fixed_price_amount;
            }
        }

        return $total;
    }

    /**
     * Get total paint material cost for this area.
     */
    public function getTotalPaintCostAttribute(): float
    {
        return (float) $this->paintSelections()->sum('total_material_cost');
    }

    /**
     * Check if this is a nested/child area.
     */
    public function getIsNestedAttribute(): bool
    {
        return $this->parent_area_id !== null;
    }

    // =========================================================================
    // PRICING METHODS - CLEAR SEPARATION OF COGS vs CUSTOMER PRICE
    // =========================================================================
    // ARCHITECTURE: Areas calculate COGS from surfaces. Customer prices
    // are calculated on-the-fly using CompanySetting margins.
    // This prevents double-margin bugs and allows easy margin changes.
    // =========================================================================

    /**
     * Get the raw COGS (Cost of Goods Sold) for this area.
     * This is the sum of surface COGSs (labor + material).
     * NO margins applied - this is what's stored in surface.total fields.
     */
    public function getCOGS(): float
    {
        // Fixed price areas don't have COGS - they are the price
        if ($this->is_fixed_price) {
            return (float) $this->fixed_price_amount;
        }

        return (float) $this->surfaces()
            ->where('is_included', true)
            ->sum('total'); // total = labor_cost + material_cost
    }

    /**
     * Get the full COGS including cleanup, sundries, and addons.
     * This is what the company actually spends to complete this area.
     * NO margins applied - this is pure cost.
     *
     * Formula: labor + cleanup + sundries + materials + addons (with taxes)
     */
    public function getFullCOGS(): float
    {
        $settings = CompanySetting::getInstance();
        $scope = $this->scope_type ?? 'interior';

        // Fixed price areas: use stored labor breakdown if available, else fall back to fixed_price_amount
        if ($this->is_fixed_price) {
            if ($this->estimated_labor_hours !== null && $this->estimated_labor_hours > 0) {
                $laborCost = (float) ($this->estimated_labor_cost ?? 0);
                $materialCost = (float) ($this->estimated_material_cost ?? 0);
                $laborHours = (float) $this->estimated_labor_hours;

                $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
                $cleanupCost = $cleanupHours * $settings->getEffectiveHourlyRateForScope($scope);
                $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);

                $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost);

                return $cogsResult['cogs'];
            }

            return (float) $this->fixed_price_amount;
        }

        // Sum from surfaces
        $laborHours = (float) $this->surfaces()->where('is_included', true)->sum('labor_hours');
        $laborCost = (float) $this->surfaces()->where('is_included', true)->sum('labor_cost');
        $materialCost = (float) $this->surfaces()->where('is_included', true)->sum('material_cost');

        // Add cleanup, sundries from CompanySetting (effective rate = base wage × payroll burden)
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
        $cleanupCost = $cleanupHours * $settings->getEffectiveHourlyRateForScope($scope);
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);

        // Add addon costs (nested area templates like closets)
        $addonCost = (float) $this->addons()->sum('total_price');

        // Calculate COGS with taxes
        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost + $addonCost);

        return $cogsResult['cogs'];
    }

    /**
     * Populate labor tracking fields from the NestedAreaTemplate.
     * Only sets values that are currently null (won't overwrite explicit values).
     * Mirrors EstimateAreaAddon::populateLaborFromTemplate().
     */
    public function populateLaborFromTemplate(): void
    {
        $template = $this->nestedAreaTemplate;

        if (! $template) {
            return;
        }

        if ($this->estimated_labor_hours === null && $template->estimated_hours !== null) {
            $this->estimated_labor_hours = (float) $template->estimated_hours;
        }

        if ($this->estimated_material_cost === null && $template->material_cost !== null) {
            $this->estimated_material_cost = (float) $template->material_cost;
        }

        if ($this->estimated_labor_cost === null && $this->estimated_labor_hours !== null && $this->estimated_labor_hours > 0) {
            $scope = $this->scope_type ?? 'interior';
            $settings = CompanySetting::getInstance();
            $this->estimated_labor_cost = (float) $this->estimated_labor_hours * $settings->getEffectiveHourlyRateForScope($scope);
        }
    }

    /**
     * Get the customer-facing price (OTD) for this area.
     * This applies the scope-specific margin from CompanySetting.
     * PBP Formula: COGS / (margin% / 100)
     *
     * Supports mixed pricing: if any custom surface has an explicit sale_price,
     * those surfaces contribute their sale_price directly while non-custom surfaces
     * get margin applied normally. Overhead (cleanup/sundries) is applied via margin
     * to the non-custom COGS portion.
     *
     * USE THIS for displaying prices to customers.
     * Margins are ALWAYS pulled fresh from CompanySetting - never hardcoded.
     */
    /**
     * Get the parent estimate for pricing purposes.
     * Uses the already-loaded relationship if available, otherwise loads it.
     * Caches on the model instance to avoid repeated queries within a request.
     */
    protected function getEstimateForPricing(): ?Estimate
    {
        if ($this->relationLoaded('estimate')) {
            return $this->estimate;
        }

        if ($this->estimate_id) {
            $this->setRelation('estimate', Estimate::find($this->estimate_id));

            return $this->estimate;
        }

        return null;
    }

    public function getCustomerPrice(): float
    {
        // Fixed price areas already include everything
        if ($this->is_fixed_price) {
            return (float) $this->fixed_price_amount;
        }

        $settings = CompanySetting::getInstance();
        $scope = $this->scope_type ?? 'interior';

        // Check if any custom surfaces have explicit sale_price
        $surfaces = $this->surfaces()->where('is_included', true)->get();
        $hasCustomSalePrices = $surfaces->contains(fn ($s) => $s->is_custom && $s->sale_price !== null);

        if (! $hasCustomSalePrices) {
            // Standard path: all COGS get margin applied uniformly
            $cogs = $this->getFullCOGS();

            if ($cogs <= 0) {
                return 0.0;
            }

            // Use estimate-level discounted margin if available
            $estimate = $this->getEstimateForPricing();
            if ($estimate && $estimate->hasMarginDiscount()) {
                return $estimate->calculateOTDFromCOGS($cogs, $scope);
            }

            return $settings->calculateOTDFromCOGS($cogs, $scope);
        }

        // Mixed pricing: custom sale_price surfaces + margin-calculated non-custom surfaces
        $customSaleTotal = 0.0;
        $nonCustomLaborHours = 0.0;
        $nonCustomLaborCost = 0.0;
        $nonCustomMaterialCost = 0.0;
        $totalLaborHours = 0.0;

        foreach ($surfaces as $surface) {
            $totalLaborHours += (float) $surface->labor_hours;

            if ($surface->is_custom && $surface->sale_price !== null) {
                $customSaleTotal += (float) $surface->sale_price;
            } else {
                $nonCustomLaborHours += (float) $surface->labor_hours;
                $nonCustomLaborCost += (float) $surface->labor_cost;
                $nonCustomMaterialCost += (float) $surface->material_cost;
            }
        }

        // Calculate COGS for non-custom portion (including their share of overhead)
        $nonCustomPrice = 0.0;
        if ($nonCustomLaborCost > 0 || $nonCustomMaterialCost > 0) {
            $cleanupHours = $settings->calculateCleanupHours($nonCustomLaborHours, $scope);
            $cleanupCost = $cleanupHours * $settings->getEffectiveHourlyRateForScope($scope);
            $sundriesCost = $settings->calculateSundriesCost($nonCustomLaborHours, $scope);

            $addonCost = (float) $this->addons()->sum('total_price');
            $cogsResult = $settings->calculateCOGS($nonCustomLaborCost, $cleanupCost, $sundriesCost, $nonCustomMaterialCost + $addonCost);

            // Use estimate-level discounted margin if available
            $estimate = $this->getEstimateForPricing();
            if ($estimate && $estimate->hasMarginDiscount()) {
                $nonCustomPrice = $estimate->calculateOTDFromCOGS($cogsResult['cogs'], $scope);
            } else {
                $nonCustomPrice = $settings->calculateOTDFromCOGS($cogsResult['cogs'], $scope);
            }
        }

        return $customSaleTotal + $nonCustomPrice;
    }

    /**
     * Get proportionally-allocated customer prices for each surface.
     * Distributes the area's customer price based on each surface's COGS contribution.
     * Ensures surface prices sum exactly to the area customer price.
     *
     * Custom surfaces with explicit sale_price get their sale_price directly.
     * Non-custom surfaces share the remaining customer price proportionally.
     *
     * @return array<int, float> Map of surface_id => proportional customer price
     */
    public function getSurfaceCustomerPrices(): array
    {
        $customerPrice = $this->getCustomerPrice();
        $surfaces = $this->surfaces()->where('is_included', true)->get();

        if ($surfaces->isEmpty()) {
            return [];
        }

        // Check for custom sale_price surfaces
        $hasCustomSalePrices = $surfaces->contains(fn ($s) => $s->is_custom && $s->sale_price !== null);

        if (! $hasCustomSalePrices) {
            // Standard proportional allocation
            $totalCogs = (float) $surfaces->sum('total');

            if ($totalCogs <= 0) {
                return [];
            }

            $prices = [];
            $allocated = 0.0;
            $lastId = $surfaces->last()->id;

            foreach ($surfaces as $surface) {
                if ($surface->id === $lastId) {
                    $prices[$surface->id] = round($customerPrice - $allocated, 2);
                } else {
                    $price = round(((float) $surface->total / $totalCogs) * $customerPrice, 2);
                    $prices[$surface->id] = $price;
                    $allocated += $price;
                }
            }

            return $prices;
        }

        // Mixed: custom sale_price surfaces get their price, rest share proportionally
        $prices = [];
        $customTotal = 0.0;
        $nonCustomSurfaces = [];
        $nonCustomCogs = 0.0;

        foreach ($surfaces as $surface) {
            if ($surface->is_custom && $surface->sale_price !== null) {
                $prices[$surface->id] = (float) $surface->sale_price;
                $customTotal += (float) $surface->sale_price;
            } else {
                $nonCustomSurfaces[] = $surface;
                $nonCustomCogs += (float) $surface->total;
            }
        }

        // Remaining price for non-custom surfaces
        $remainingPrice = $customerPrice - $customTotal;

        if (! empty($nonCustomSurfaces) && $nonCustomCogs > 0 && $remainingPrice > 0) {
            $allocated = 0.0;
            $lastNonCustom = end($nonCustomSurfaces);

            foreach ($nonCustomSurfaces as $surface) {
                if ($surface->id === $lastNonCustom->id) {
                    $prices[$surface->id] = round($remainingPrice - $allocated, 2);
                } else {
                    $price = round(((float) $surface->total / $nonCustomCogs) * $remainingPrice, 2);
                    $prices[$surface->id] = $price;
                    $allocated += $price;
                }
            }
        }

        return $prices;
    }

    /**
     * Get the customer price for a subset of surfaces in this area.
     * Uses proportional allocation: (included COGS / total COGS) × full area price.
     *
     * @param  array<int>  $includedSurfaceIds  Surface IDs to include in pricing
     */
    public function getPartialCustomerPrice(array $includedSurfaceIds): float
    {
        if (empty($includedSurfaceIds)) {
            return 0.0;
        }

        if ($this->is_fixed_price) {
            return (float) $this->fixed_price_amount;
        }

        $allSurfaces = $this->surfaces()->where('is_included', true)->get();
        $totalCogs = (float) $allSurfaces->sum('total');

        if ($totalCogs <= 0) {
            return 0.0;
        }

        $includedCogs = (float) $allSurfaces->whereIn('id', $includedSurfaceIds)->sum('total');

        if ($includedCogs <= 0) {
            return 0.0;
        }

        // All surfaces included - return full price (avoids rounding drift)
        if ($includedCogs >= $totalCogs) {
            return $this->getCustomerPrice();
        }

        return round(($includedCogs / $totalCogs) * $this->getCustomerPrice(), 2);
    }

    /**
     * Calculate the OTD (Out The Door) price for this area using PBP formula.
     * ALIAS for getCustomerPrice() - kept for backward compatibility.
     *
     * @deprecated Use getCustomerPrice() for clarity
     */
    public function calculateOTD(): float
    {
        return $this->getCustomerPrice();
    }

    /**
     * Scope to get only top-level areas (not nested).
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_area_id');
    }

    /**
     * Scope to get only nested areas.
     */
    public function scopeNested(Builder $query): Builder
    {
        return $query->whereNotNull('parent_area_id');
    }

    /**
     * Scope by scope type (interior/exterior).
     */
    public function scopeForScope(Builder $query, string $scopeType): Builder
    {
        return $query->where('scope_type', $scopeType);
    }

    /**
     * Scope to interior areas.
     */
    public function scopeInterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_INTERIOR);
    }

    /**
     * Scope to exterior areas.
     */
    public function scopeExterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_EXTERIOR);
    }

    /**
     * Scope to get areas with only included surfaces.
     */
    public function scopeWithIncludedSurfaces(Builder $query): Builder
    {
        return $query->with(['surfaces' => function ($query) {
            $query->where('is_included', true);
        }]);
    }

    /**
     * Get available scope types.
     *
     * @return array<string, string>
     */
    public static function getScopeTypes(): array
    {
        return [
            self::SCOPE_INTERIOR => 'Interior',
            self::SCOPE_EXTERIOR => 'Exterior',
        ];
    }

    /**
     * Get available application methods.
     *
     * @return array<string, string>
     */
    public static function getApplicationMethods(): array
    {
        return [
            self::APPLICATION_BRUSH_ROLL => 'Brush/Roll',
            self::APPLICATION_SPRAY => 'Spray',
        ];
    }
}
