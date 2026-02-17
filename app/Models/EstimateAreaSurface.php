<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateAreaSurface extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateAreaSurfaceFactory> */
    use HasFactory;

    protected $fillable = [
        'area_id',
        'production_rate_id',
        'name',
        'surface_type',
        'description',
        'quantity',
        'unit',
        'coats',
        'application_method',
        'paint_brand',
        'paint_type',
        'paint_gallons',
        'paint_cost',
        'production_rate_value',
        'hourly_labor_rate',
        'material_cost_per_unit',
        'labor_hours',
        'labor_cost',
        'material_cost',
        'total',
        'is_included',
        'is_custom',
        'sort_order',
        'paint_product_id',
        'material_cost_good',
        'material_cost_better',
        'material_cost_best',
        'paint_product_id_good',
        'paint_product_id_better',
        'paint_product_id_best',
        'sale_price',
        'crew_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'production_rate_value' => 'decimal:2',
            'hourly_labor_rate' => 'decimal:2',
            'material_cost_per_unit' => 'decimal:2',
            'labor_hours' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'paint_gallons' => 'decimal:2',
            'paint_cost' => 'decimal:2',
            'is_included' => 'boolean',
            'is_custom' => 'boolean',
            'coats' => 'integer',
            'sort_order' => 'integer',
            'material_cost_good' => 'decimal:2',
            'material_cost_better' => 'decimal:2',
            'material_cost_best' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'crew_price' => 'decimal:2',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateAreaSurface $surface) {
            // Custom surfaces: manual labor hours and material costs, skip production rate calc
            if ($surface->is_custom) {
                $scopeType = $surface->area?->scope_type ?? 'interior';
                $settings = CompanySetting::getInstance();

                // Use custom rate if set, otherwise estimate override or company effective rate
                if ($surface->hourly_labor_rate && $surface->hourly_labor_rate > 0) {
                    $hourlyRate = (float) $surface->hourly_labor_rate;
                } else {
                    $estimate = $surface->area?->estimate;
                    $hourlyRate = ($estimate && $estimate->hasHourlyRateOverride())
                        ? $estimate->getOverrideEffectiveHourlyRate($scopeType)
                        : $settings->getEffectiveHourlyRateForScope($scopeType);
                }

                $surface->hourly_labor_rate = $hourlyRate;
                $surface->labor_cost = (float) ($surface->labor_hours ?? 0) * $hourlyRate;
                $surface->total = (float) $surface->labor_cost + (float) ($surface->material_cost ?? 0);

                return;
            }

            // Skip auto-calculation if values are already set (e.g., from TemplateBuilder)
            // This prevents overwriting paint product calculations
            // But always recalculate if paint_product_id just changed (material cost needs updating)
            if ($surface->labor_hours !== null && $surface->material_cost !== null && $surface->total !== null && ! $surface->isDirty('paint_product_id')) {
                return;
            }

            // Calculate costs if we have the required values
            if ($surface->quantity && $surface->production_rate_value) {
                $coats = $surface->coats ?? 1;

                // Labor hours = quantity / production_rate_value * coat_multiplier
                $rate = $surface->productionRate;
                $coatMultiplier = $rate ? $rate->getCoatMultiplier($coats) : ($coats === 1 ? 1.0 : ($coats === 2 ? 1.8 : 2.5));

                // Get substrate multipliers from area (prep and paint time adjustments)
                $substrate = $surface->area?->substrate;
                $prepMultiplier = $substrate ? (float) ($substrate->prep_time_multiplier ?? 1.0) : 1.0;
                $paintMultiplier = $substrate ? (float) ($substrate->paint_time_multiplier ?? 1.0) : 1.0;

                // Apply substrate multipliers: (quantity / rate) × prep_multiplier × paint_multiplier × coat_multiplier
                $surface->labor_hours = ($surface->quantity / $surface->production_rate_value) * $prepMultiplier * $paintMultiplier * $coatMultiplier;

                // Labor cost = labor_hours * effective hourly rate (estimate override or CompanySetting)
                $scopeType = $surface->area?->scope_type ?? 'interior';
                $settings = CompanySetting::getInstance();
                $estimate = $surface->area?->estimate;
                $effectiveRate = ($estimate && $estimate->hasHourlyRateOverride())
                    ? $estimate->getOverrideEffectiveHourlyRate($scopeType)
                    : $settings->getEffectiveHourlyRateForScope($scopeType);

                // Store the hourly rate for reference
                $surface->hourly_labor_rate = $effectiveRate;
                $surface->labor_cost = $surface->labor_hours * $effectiveRate;

                // Material cost - use paint product if available, otherwise fallback
                // Substrate's material_multiplier affects paint coverage
                $materialMultiplier = $substrate ? (float) ($substrate->material_multiplier ?? 1.0) : 1.0;

                if ($surface->paint_product_id && ! $surface->paint_cost) {
                    // Calculate using paint product (calculatePaintMaterialCost handles substrate internally)
                    $materialCost = $surface->calculatePaintMaterialCost();
                    $surface->material_cost = $materialCost;
                } elseif ($surface->paint_cost) {
                    // Use pre-calculated paint cost (includes recycle fee)
                    $settings = CompanySetting::getInstance();
                    $recycleFee = ($surface->paint_gallons ?? 0) * (float) $settings->recycle_fee_per_gallon;
                    $surface->material_cost = ($surface->paint_cost + $recycleFee) * $materialMultiplier;
                } else {
                    // Fallback: quantity * material_cost_per_unit * coatMultiplier * materialMultiplier
                    $surface->material_cost = $surface->quantity * ($surface->material_cost_per_unit ?? 0) * $coatMultiplier * $materialMultiplier;
                }

                // Total = labor_cost + material_cost
                $surface->total = $surface->labor_cost + $surface->material_cost;
            }
        });

        // Trigger estimate and package recalculation when a surface is saved
        // Skip recalculation if the estimate was just updated (it already has correct values from TemplateBuilder)
        static::saved(function (EstimateAreaSurface $surface) {
            // Only recalculate if the estimate wasn't recently updated (within last second)
            // This prevents the save flow from double-calculating
            $estimate = $surface->area?->estimate;
            if ($estimate && $estimate->updated_at && $estimate->updated_at->lt(now()->subSecond())) {
                $estimate->calculateTotals();
                $estimate->calculateDepositAndBalance();
                $estimate->save();

                // Recalculate package totals since surfaces affect package pricing via effective_price
                foreach ($estimate->packages as $package) {
                    $package->calculateTotals();
                    $package->save();
                }
            }
        });

        // Trigger estimate and package recalculation when a surface is deleted
        static::deleted(function (EstimateAreaSurface $surface) {
            $estimate = $surface->area?->estimate;
            if ($estimate && $estimate->exists) {
                $estimate->calculateTotals();
                $estimate->calculateDepositAndBalance();
                $estimate->save();

                // Recalculate package totals since surfaces affect package pricing
                foreach ($estimate->packages as $package) {
                    $package->calculateTotals();
                    $package->save();
                }
            }
        });
    }

    /**
     * Get the area for this surface.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class, 'area_id');
    }

    /**
     * Get the production rate for this surface.
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * Get the paint product for this surface (overrides area/estimate default).
     */
    public function paintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class);
    }

    /**
     * Get the surface category for this surface.
     * Maps surface_type to a normalized category for paint selection.
     *
     * Interior categories: walls, ceiling, trim, doors
     * Exterior categories: body, trim, fascia, eaves, doors
     */
    public function getSurfaceCategory(): string
    {
        $surfaceType = strtolower($this->surface_type ?? 'other');
        $surfaceName = strtolower($this->name ?? '');

        // Check by name first (more specific)
        if (str_contains($surfaceName, 'wall')) {
            return 'walls';
        }
        if (str_contains($surfaceName, 'ceiling')) {
            return 'ceiling';
        }
        if (str_contains($surfaceName, 'door')) {
            return 'doors';
        }
        if (str_contains($surfaceName, 'baseboard') || str_contains($surfaceName, 'crown') || str_contains($surfaceName, 'molding')) {
            return 'trim';
        }
        if (str_contains($surfaceName, 'fascia')) {
            return 'fascia';
        }
        if (str_contains($surfaceName, 'soffit') || str_contains($surfaceName, 'eave')) {
            return 'eaves';
        }
        if (str_contains($surfaceName, 'siding') || str_contains($surfaceName, 'body')) {
            return 'body';
        }

        // Fall back to surface_type
        return match ($surfaceType) {
            'walls', 'wall', 'sqft' => 'walls',
            'ceiling' => 'ceiling',
            'trim', 'baseboard', 'crown', 'molding', 'lnft' => 'trim',
            'doors', 'door', 'unit' => 'doors',
            'body', 'siding' => 'body',
            'fascia' => 'fascia',
            'eaves', 'soffit' => 'eaves',
            default => 'other',
        };
    }

    /**
     * Get the effective paint product for this surface.
     * Cascades: Surface → Estimate Category → Company Category → Area default → Estimate default → null
     */
    public function getEffectivePaintProduct(): ?PaintProduct
    {
        // 1. Surface-level override (highest priority)
        if ($this->paint_product_id) {
            return $this->paintProduct;
        }

        // 2. Category-based cascade (estimate → company)
        $category = $this->getSurfaceCategory();
        if ($category !== 'other') {
            $estimate = $this->area?->estimate;
            if ($estimate) {
                $categoryPaint = $estimate->resolvePaintForCategory($category);
                if ($categoryPaint) {
                    return $categoryPaint;
                }
            }
        }

        // 3. Fall back to area/estimate cascade
        return $this->area?->getEffectivePaintProduct();
    }

    /**
     * Calculate material cost using the effective paint product.
     * Returns the paint cost based on coverage and pricing.
     */
    public function calculatePaintMaterialCost(): float
    {
        $paintProduct = $this->getEffectivePaintProduct();

        if (! $paintProduct) {
            // Fall back to simple material_cost_per_unit calculation
            $coatMultiplier = $this->coats === 1 ? 1.0 : ($this->coats === 2 ? 1.8 : 2.5);

            return (float) $this->quantity * (float) ($this->material_cost_per_unit ?? 0.15) * $coatMultiplier;
        }

        // Use paint product's coverage and pricing
        $applicationMethod = $this->application_method ?? 'brush_roll';
        $costCalc = $paintProduct->calculateCost(
            (float) $this->quantity,
            $this->coats ?? 2,
            $applicationMethod
        );

        return $costCalc['total_cost'];
    }

    /**
     * Create a surface from a production rate.
     *
     * Labor costs are calculated using CompanySetting rates based on area scope.
     * Material costs come from PaintProduct pricing or fallback.
     */
    public static function createFromRate(
        EstimateArea $area,
        ProductionRate $rate,
        float $quantity,
        ?int $coats = null,
        string $applicationMethod = 'brush_roll',
        ?string $paintBrand = null,
        ?string $paintType = null
    ): static {
        $coats = $coats ?? $rate->default_coats ?? 1;

        // Get effective rate: estimate override → Company Settings based on area scope
        $scopeType = $area->scope_type ?? 'interior';
        $settings = CompanySetting::getInstance();
        $estimate = $area->estimate;
        $effectiveRate = ($estimate && $estimate->hasHourlyRateOverride())
            ? $estimate->getOverrideEffectiveHourlyRate($scopeType)
            : $settings->getEffectiveHourlyRateForScope($scopeType);

        return static::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => $rate->name,
            'surface_type' => $rate->unit_type,
            'quantity' => $quantity,
            'unit' => $rate->unit_type,
            'coats' => $coats,
            'application_method' => $applicationMethod,
            'paint_brand' => $paintBrand,
            'paint_type' => $paintType,
            'production_rate_value' => $rate->production_rate,
            'hourly_labor_rate' => $effectiveRate, // From CompanySetting, not ProductionRate
            'material_cost_per_unit' => 0, // Materials come from PaintProduct
            'is_included' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * Calculate and store paint needs for this surface.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total_cost: float}|null
     */
    public function calculatePaintNeeds(?string $brand = null, ?string $type = null): ?array
    {
        $service = app(\App\Services\PaintCalculatorService::class);

        $brand = $brand ?? $this->paint_brand ?? 'sherwin_williams';
        $type = $type ?? $this->paint_type ?? 'superpaint';

        $result = $service->calculatePaintCost(
            (float) $this->quantity,
            $brand,
            $type,
            $this->coats ?? 2,
            $this->application_method ?? 'brush_roll'
        );

        if ($result) {
            $this->paint_gallons = $result['gallons_rounded'];
            $this->paint_cost = $result['total_cost'];
            $this->paint_brand = $brand;
            $this->paint_type = $type;
            $this->save();
        }

        return $result;
    }

    /**
     * Get the application method display name.
     */
    public function getApplicationMethodLabelAttribute(): string
    {
        return match ($this->application_method) {
            'spray' => __('Spray'),
            'brush_roll' => __('Brush/Roll'),
            default => __(ucfirst($this->application_method ?? 'brush_roll')),
        };
    }

    /**
     * Get formatted paint brand name.
     */
    public function getPaintBrandLabelAttribute(): ?string
    {
        if (! $this->paint_brand) {
            return null;
        }

        return __(ucwords(str_replace('_', ' ', $this->paint_brand)));
    }

    /**
     * Get formatted paint type name.
     */
    public function getPaintTypeLabelAttribute(): ?string
    {
        if (! $this->paint_type) {
            return null;
        }

        return __(ucwords(str_replace('_', ' ', $this->paint_type)));
    }

    // =========================================================================
    // TIER PRICING METHODS
    // =========================================================================

    /**
     * Get the material cost for a specific tier.
     * Falls back to the standard material_cost if tier column is null.
     */
    public function getMaterialCostForTier(?string $tier): float
    {
        if ($tier === null) {
            return (float) $this->material_cost;
        }

        $tierColumn = "material_cost_{$tier}";
        $value = $this->{$tierColumn};

        return $value !== null ? (float) $value : (float) $this->material_cost;
    }

    /**
     * Get the paint product for a specific tier.
     */
    public function getPaintProductForTier(?string $tier): ?PaintProduct
    {
        if ($tier === null) {
            return $this->paintProduct;
        }

        $tierColumn = "paint_product_id_{$tier}";
        $productId = $this->{$tierColumn};

        return $productId ? PaintProduct::find($productId) : $this->paintProduct;
    }

    // =========================================================================
    // CUSTOM PRICING METHODS
    // =========================================================================

    /**
     * Check if this surface has explicit custom pricing overrides.
     * True when is_custom AND has sale_price or crew_price set.
     */
    public function hasCustomPricing(): bool
    {
        return $this->is_custom && ($this->sale_price !== null || $this->crew_price !== null);
    }

    /**
     * Get the effective sale price for this surface.
     * Returns explicit sale_price if set, otherwise the proportional customer price via margin.
     */
    public function getEffectiveSalePrice(): float
    {
        if ($this->is_custom && $this->sale_price !== null) {
            return (float) $this->sale_price;
        }

        return $this->getCustomerPrice();
    }

    /**
     * Get the effective crew hourly rate for this surface.
     * Returns explicit crew_price ($/hr) if set, otherwise base wage from CompanySetting.
     */
    public function getEffectiveCrewRate(): float
    {
        if ($this->is_custom && $this->crew_price !== null) {
            return (float) $this->crew_price;
        }

        $scopeType = $this->area?->scope_type ?? 'interior';
        $settings = CompanySetting::getInstance();

        return $settings->getHourlyWageForScope($scopeType);
    }

    /**
     * Get crew payment for this surface (what crew earns).
     * crew_price (hourly rate) * labor_hours.
     */
    public function getCrewPayment(): float
    {
        return $this->getEffectiveCrewRate() * (float) $this->labor_hours;
    }

    // =========================================================================
    // PRICING METHODS - CLEAR SEPARATION OF COGS vs CUSTOMER PRICE
    // =========================================================================
    // ARCHITECTURE: Surfaces store COGS (labor + material) in the `total` field.
    // Margin is applied ONCE at the AREA level (EstimateArea::getCustomerPrice()).
    // Surface getCustomerPrice() exists for display-only purposes (e.g., proposal
    // breakdowns, package items) - it is NOT used in estimate total calculations.
    // =========================================================================

    /**
     * Get the raw COGS (Cost of Goods Sold) for this surface.
     * This is the STORED value: labor_cost + material_cost.
     * NO margins applied - this is pure cost.
     */
    public function getCOGS(): float
    {
        return (float) $this->total; // total = labor_cost + material_cost
    }

    /**
     * Get the full COGS including this surface's share of cleanup and sundries.
     * This is what the company actually spends to paint this surface.
     * NO margins applied - this is pure cost.
     *
     * Formula: labor + cleanup + sundries + materials (with taxes)
     *
     * @param  string|null  $tier  Paint quality tier (good/better/best) or null for default
     */
    public function getFullCOGS(?string $tier = null): float
    {
        $settings = CompanySetting::getInstance();
        $scopeType = $this->area?->scope_type ?? 'interior';

        $laborHours = (float) $this->labor_hours;
        $laborCost = (float) $this->labor_cost;
        $materialCost = $this->getMaterialCostForTier($tier);

        // This surface's proportional share of cleanup and sundries
        // Use effective rate (with payroll burden) to match area-level calculation
        // Cleanup and sundries are the SAME across all tiers (labor doesn't change)
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $settings->getEffectiveHourlyRateForScope($scopeType);
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost);

        return $cogsResult['cogs'];
    }

    /**
     * Get the customer-facing price (OTD) for this surface.
     * PBP Formula: COGS / (margin% / 100)
     *
     * NOTE: This is for DISPLAY purposes only (e.g., proposal surface breakdown,
     * package items). Estimate totals use area-level margin via EstimateArea::getCustomerPrice().
     *
     * @param  string|null  $tier  Paint quality tier (good/better/best) or null for default
     */
    public function getCustomerPrice(?string $tier = null): float
    {
        $cogs = $this->getFullCOGS($tier);

        if ($cogs <= 0) {
            return 0.0;
        }

        $settings = CompanySetting::getInstance();
        $scopeType = $this->area?->scope_type ?? 'interior';

        return $settings->calculateOTDFromCOGS($cogs, $scopeType);
    }

    /**
     * Calculate the OTD (Out The Door) price for this surface with margins applied.
     * ALIAS for getCustomerPrice() - display only, not used in estimate totals.
     */
    public function calculateOTD(): float
    {
        return $this->getCustomerPrice();
    }
}
