<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

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
        'cached_cogs',
        'cached_customer_price',
        'cached_tier_prices',
        'notes',
        'customer_notes',
        'customer_checklist',
        'is_customer_added',
        'agent_notes',
        'agent_checklist',
        'sort_order',
        'default_paint_product_id',
        'paint_tier_overrides',
        'measurement_type',
        'wall_count',
        'direct_sqft',
        'direct_lnft',
        'direct_ceiling_sqft',
        'service_category_id',
        'package_tiers',
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
            'cached_cogs' => 'float',
            'cached_customer_price' => 'float',
            'cached_tier_prices' => 'array',
            'customer_checklist' => 'array',
            'is_customer_added' => 'boolean',
            'agent_checklist' => 'array',
            'sort_order' => 'integer',
            'wall_count' => 'integer',
            'direct_sqft' => 'decimal:2',
            'direct_lnft' => 'decimal:2',
            'direct_ceiling_sqft' => 'decimal:2',
            'paint_tier_overrides' => 'array',
            'package_tiers' => 'array',
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
                    $area->perimeter_lnft = $area->perimeter_lnft ?? 0;
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
     * Get cost items for this area (materials, rentals, equipment).
     */
    public function costItems(): MorphMany
    {
        return $this->morphMany(EstimateCostItem::class, 'costable');
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
    // PER-TIER VISIBILITY
    // =========================================================================

    /**
     * Check if this area is included in a specific package tier.
     * Returns true if package_tiers is null (all tiers) or if tier is in the list.
     */
    public function isIncludedInTier(string $tier): bool
    {
        if ($this->package_tiers === null) {
            return true;
        }

        return in_array($tier, $this->package_tiers, true);
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
     *
     * @param  string|null  $tier  Paint quality tier (good/better/best) or null for default
     */
    public function getFullCOGS(?string $tier = null, bool $bypassCache = false): float
    {
        // Use cached COGS when available (snapshot from template/area builder)
        if (! $bypassCache) {
            if ($tier === null || $tier === 'good') {
                if ($this->cached_cogs !== null) {
                    return (float) $this->cached_cogs;
                }
            } elseif ($this->cached_tier_prices !== null) {
                $tierCogs = $this->cached_tier_prices[$tier]['cogs'] ?? null;
                if ($tierCogs !== null) {
                    return (float) $tierCogs;
                }
            }
        }

        $settings = CompanySetting::getInstance();
        $scope = $this->scope_type ?? 'interior';
        $estimate = $this->getEstimateForPricing();

        // Resolve effective hourly rate: estimate override → global setting
        $effectiveRate = ($estimate && $estimate->hasHourlyRateOverride())
            ? $estimate->getOverrideEffectiveHourlyRate($scope)
            : $settings->getEffectiveHourlyRateForScope($scope);

        // Fixed price areas: use stored labor breakdown if available, else fall back to fixed_price_amount
        // Fixed price areas are unchanged by tier
        if ($this->is_fixed_price) {
            if ($this->estimated_labor_hours !== null && $this->estimated_labor_hours > 0) {
                $laborCost = (float) ($this->estimated_labor_cost ?? 0);
                $materialCost = (float) ($this->estimated_material_cost ?? 0);
                $laborHours = (float) $this->estimated_labor_hours;

                $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
                $cleanupCost = $cleanupHours * $effectiveRate;
                $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);

                $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost);

                return $cogsResult['cogs'];
            }

            return (float) $this->fixed_price_amount;
        }

        // Sum from surfaces - use tier-specific labor/material when overrides exist
        $surfaces = $this->surfaces()->where('is_included', true);

        if ($tier !== null) {
            $laborHours = 0.0;
            $laborCost = 0.0;
            $materialCost = 0.0;
            foreach ($surfaces->get() as $surface) {
                $laborHours += $surface->getLaborHoursForTier($tier);
                $laborCost += $surface->getLaborCostForTier($tier);
                $materialCost += $surface->getMaterialCostForTier($tier);
            }
        } else {
            $laborHours = (float) $surfaces->sum('labor_hours');
            $laborCost = (float) $surfaces->sum('labor_cost');
            $materialCost = (float) $surfaces->sum('material_cost');
        }

        // Add cleanup, sundries (effective rate = base wage × payroll burden, with override if set)
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
        $cleanupCost = $cleanupHours * $effectiveRate;
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);

        // Add addon costs (nested area templates like closets)
        $addonCost = (float) $this->addons()->sum('total_price');

        // Add cost items (materials, rentals, equipment) to COGS
        $costItemsCost = (float) $this->costItems()->sum('total_cost');
        $surfaceCostItemsCost = 0.0;
        foreach ($this->surfaces()->where('is_included', true)->get() as $s) {
            $surfaceCostItemsCost += (float) $s->costItems()->sum('total_cost');
        }

        // Calculate COGS with taxes
        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost + $addonCost + $costItemsCost + $surfaceCostItemsCost);

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
            $estimate = $this->getEstimateForPricing();
            $effectiveRate = ($estimate && $estimate->hasHourlyRateOverride())
                ? $estimate->getOverrideEffectiveHourlyRate($scope)
                : $settings->getEffectiveHourlyRateForScope($scope);
            $this->estimated_labor_cost = (float) $this->estimated_labor_hours * $effectiveRate;
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

    /**
     * @param  string|null  $tier  Paint quality tier (good/better/best) or null for default
     */
    public function getCustomerPrice(?string $tier = null): float
    {
        // Fixed price areas already include everything
        if ($this->is_fixed_price) {
            return (float) $this->fixed_price_amount;
        }

        // Use cached customer price when available (snapshot from template/area builder)
        if ($tier === null || $tier === 'good') {
            if ($this->cached_customer_price !== null) {
                return (float) $this->cached_customer_price;
            }
        } elseif ($this->cached_tier_prices !== null) {
            $tierPrice = $this->cached_tier_prices[$tier]['price'] ?? null;
            if ($tierPrice !== null) {
                return (float) $tierPrice;
            }
        }

        $settings = CompanySetting::getInstance();
        $scope = $this->scope_type ?? 'interior';

        // Check if any custom surfaces have explicit sale_price
        $surfaces = $this->surfaces()->where('is_included', true)->get();
        $hasCustomSalePrices = $surfaces->contains(fn ($s) => $s->is_custom && $s->sale_price !== null);

        if (! $hasCustomSalePrices) {
            // Standard path: all COGS get margin applied uniformly
            $cogs = $this->getFullCOGS($tier);

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
            $totalLaborHours += $surface->getLaborHoursForTier($tier);

            if ($surface->is_custom && $surface->sale_price !== null) {
                $customSaleTotal += (float) $surface->sale_price;
            } else {
                $nonCustomLaborHours += $surface->getLaborHoursForTier($tier);
                $nonCustomLaborCost += $surface->getLaborCostForTier($tier);
                $nonCustomMaterialCost += $surface->getMaterialCostForTier($tier);
            }
        }

        // Calculate COGS for non-custom portion (including their share of overhead)
        $nonCustomPrice = 0.0;
        if ($nonCustomLaborCost > 0 || $nonCustomMaterialCost > 0) {
            $estimate = $this->getEstimateForPricing();
            $effectiveRate = ($estimate && $estimate->hasHourlyRateOverride())
                ? $estimate->getOverrideEffectiveHourlyRate($scope)
                : $settings->getEffectiveHourlyRateForScope($scope);
            $cleanupHours = $settings->calculateCleanupHours($nonCustomLaborHours, $scope);
            $cleanupCost = $cleanupHours * $effectiveRate;
            $sundriesCost = $settings->calculateSundriesCost($nonCustomLaborHours, $scope);

            $addonCost = (float) $this->addons()->sum('total_price');
            $cogsResult = $settings->calculateCOGS($nonCustomLaborCost, $cleanupCost, $sundriesCost, $nonCustomMaterialCost + $addonCost);

            // Use estimate-level discounted margin if available
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
    public function getSurfaceCustomerPrices(?string $tier = null): array
    {
        $customerPrice = $this->getCustomerPrice($tier);
        $surfaces = $this->surfaces()->where('is_included', true)->get();

        if ($surfaces->isEmpty()) {
            return [];
        }

        // Check for custom sale_price surfaces
        $hasCustomSalePrices = $surfaces->contains(fn ($s) => $s->is_custom && $s->sale_price !== null);

        if (! $hasCustomSalePrices) {
            // Standard proportional allocation - use tier-specific COGS when available
            if ($tier !== null) {
                $totalCogs = 0.0;
                foreach ($surfaces as $surface) {
                    $totalCogs += $surface->getLaborCostForTier($tier) + $surface->getMaterialCostForTier($tier);
                }
            } else {
                $totalCogs = (float) $surfaces->sum('total');
            }

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
                    if ($tier !== null) {
                        $surfaceCogs = $surface->getLaborCostForTier($tier) + $surface->getMaterialCostForTier($tier);
                    } else {
                        $surfaceCogs = (float) $surface->total;
                    }
                    $price = round(($surfaceCogs / $totalCogs) * $customerPrice, 2);
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
                if ($tier !== null) {
                    $nonCustomCogs += $surface->getLaborCostForTier($tier) + $surface->getMaterialCostForTier($tier);
                } else {
                    $nonCustomCogs += (float) $surface->total;
                }
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
                    if ($tier !== null) {
                        $surfaceCogs = $surface->getLaborCostForTier($tier) + $surface->getMaterialCostForTier($tier);
                    } else {
                        $surfaceCogs = (float) $surface->total;
                    }
                    $price = round(($surfaceCogs / $nonCustomCogs) * $remainingPrice, 2);
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
     * @param  string|null  $tier  Paint quality tier (good/better/best) or null for default
     */
    public function getPartialCustomerPrice(array $includedSurfaceIds, ?string $tier = null): float
    {
        if (empty($includedSurfaceIds)) {
            return 0.0;
        }

        if ($this->is_fixed_price) {
            return (float) $this->fixed_price_amount;
        }

        $allSurfaces = $this->surfaces()->where('is_included', true)->get();

        // Use full surface COGS (includes cleanup + sundries) for accurate proportioning
        if ($tier !== null) {
            $totalCogs = 0.0;
            $includedCogs = 0.0;
            foreach ($allSurfaces as $surface) {
                $surfaceCogs = $surface->getFullCOGS($tier);
                $totalCogs += $surfaceCogs;
                if (in_array($surface->id, $includedSurfaceIds)) {
                    $includedCogs += $surfaceCogs;
                }
            }
        } else {
            $totalCogs = (float) $allSurfaces->sum('total');
            $includedCogs = (float) $allSurfaces->whereIn('id', $includedSurfaceIds)->sum('total');
        }

        if ($totalCogs <= 0 || $includedCogs <= 0) {
            return 0.0;
        }

        // All surfaces included - return full price (avoids rounding drift)
        if ($includedCogs >= $totalCogs) {
            return $this->getCustomerPrice($tier);
        }

        return round(($includedCogs / $totalCogs) * $this->getCustomerPrice($tier), 2);
    }

    /**
     * Get the COGS for a subset of surfaces in this area.
     * Uses proportional allocation: (included surface costs / total surface costs) × full COGS.
     *
     * @param  array<int>  $includedSurfaceIds  Surface IDs to include
     * @param  string|null  $tier  Paint quality tier (good/better/best) or null for default
     */
    public function getPartialCOGS(array $includedSurfaceIds, ?string $tier = null): float
    {
        if (empty($includedSurfaceIds)) {
            return 0.0;
        }

        if ($this->is_fixed_price) {
            return $this->getFullCOGS($tier);
        }

        $allSurfaces = $this->surfaces()->where('is_included', true)->get();

        if ($tier !== null) {
            $totalCogs = 0.0;
            $includedCogs = 0.0;
            foreach ($allSurfaces as $surface) {
                $surfaceCogs = $surface->getFullCOGS($tier);
                $totalCogs += $surfaceCogs;
                if (in_array($surface->id, $includedSurfaceIds)) {
                    $includedCogs += $surfaceCogs;
                }
            }
        } else {
            $totalCogs = (float) $allSurfaces->sum('total');
            $includedCogs = (float) $allSurfaces->whereIn('id', $includedSurfaceIds)->sum('total');
        }

        if ($totalCogs <= 0 || $includedCogs <= 0) {
            return 0.0;
        }

        if ($includedCogs >= $totalCogs) {
            return $this->getFullCOGS($tier);
        }

        return round(($includedCogs / $totalCogs) * $this->getFullCOGS($tier), 2);
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
