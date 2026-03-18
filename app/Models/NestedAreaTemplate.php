<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NestedAreaTemplate extends Model
{
    use HasFactory;

    public const CATEGORY_CLOSET = 'closet';

    public const CATEGORY_BATHROOM = 'bathroom';

    public const CATEGORY_UTILITY = 'utility';

    public const CATEGORY_OTHER = 'other';

    public const SCOPE_INTERIOR = 'interior';

    public const SCOPE_EXTERIOR = 'exterior';

    public const SCOPE_BOTH = 'both';

    protected $fillable = [
        'name',
        'code',
        'description',
        'category',
        'scope_type',
        'fixed_price',
        'use_margin_pricing',
        'estimated_hours',  // For backend reporting (painter pay breakdown)
        'material_cost',    // For backend reporting (company analytics)
        'include_in_package_pricing',
        'default_length',
        'default_width',
        'default_height',
        'includes_walls',
        'includes_ceiling',
        'includes_trim',
        'includes_door',
        'production_rate_id',
        'substrate_id',
        'default_paint_product_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fixed_price' => 'decimal:2',
            'use_margin_pricing' => 'boolean',
            'estimated_hours' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'include_in_package_pricing' => 'boolean',
            'default_length' => 'decimal:2',
            'default_width' => 'decimal:2',
            'default_height' => 'decimal:2',
            'includes_walls' => 'boolean',
            'includes_ceiling' => 'boolean',
            'includes_trim' => 'boolean',
            'includes_door' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the production rate for this template.
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * Get the substrate for this template.
     */
    public function substrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class);
    }

    /**
     * Get the default paint product for this template.
     */
    public function defaultPaintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class, 'default_paint_product_id');
    }

    /**
     * Get the surfaces for this template.
     */
    public function surfaces(): HasMany
    {
        return $this->hasMany(NestedTemplateSurface::class)->orderBy('sort_order');
    }

    /**
     * Get areas created from this template.
     */
    public function estimateAreas(): HasMany
    {
        return $this->hasMany(EstimateArea::class);
    }

    /**
     * Scope to only active templates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by category.
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to closets.
     */
    public function scopeClosets(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_CLOSET);
    }

    /**
     * Scope to bathrooms.
     */
    public function scopeBathrooms(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_BATHROOM);
    }

    /**
     * Scope by scope type.
     * Returns templates matching the scope OR templates with 'both' scope.
     */
    public function scopeForScope(Builder $query, string $scopeType): Builder
    {
        return $query->where(function ($q) use ($scopeType) {
            $q->where('scope_type', $scopeType)
                ->orWhere('scope_type', self::SCOPE_BOTH);
        });
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Calculate the customer price from COGS + margins using PBP formula.
     */
    public function getCalculatedPrice(?string $scopeType = null): float
    {
        $scope = $scopeType ?? $this->scope_type ?? 'interior';

        // If surfaces exist, calculate from surfaces using PBP formula
        if ($this->surfaces()->exists()) {
            return $this->calculatePriceFromSurfaces($scope);
        }

        $laborHours = (float) ($this->estimated_hours ?? 0);
        $materialCost = (float) ($this->material_cost ?? 0);

        if ($laborHours <= 0 && $materialCost <= 0) {
            return (float) $this->fixed_price;
        }

        $settings = CompanySetting::getInstance();
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scope);

        $laborCost = $laborHours * $effectiveRate;
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
        $cleanupCost = $cleanupHours * $effectiveRate;
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);

        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost);

        return $settings->calculateOTDFromCOGS($cogsResult['cogs'], $scope);
    }

    /**
     * Calculate price from surfaces using production rates and PBP formula.
     */
    protected function calculatePriceFromSurfaces(string $scope): float
    {
        $settings = CompanySetting::getInstance();
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scope);

        $totalLaborHours = 0.0;
        $totalMaterialCost = (float) ($this->material_cost ?? 0);

        foreach ($this->surfaces as $surface) {
            if (! $surface->is_default_enabled) {
                continue;
            }

            $rate = $surface->productionRate;
            if (! $rate) {
                continue;
            }

            // Calculate quantity based on template dimensions and calculation method
            $quantity = $this->calculateSurfaceQuantity($surface);
            if ($quantity <= 0) {
                continue;
            }

            $coatMultiplier = $rate->getCoatMultiplier($surface->default_coats);
            $laborHours = ($quantity / (float) $rate->production_rate) * $coatMultiplier;
            $totalLaborHours += $laborHours;
        }

        if ($totalLaborHours <= 0 && $totalMaterialCost <= 0) {
            return (float) ($this->fixed_price ?? 0);
        }

        $laborCost = $totalLaborHours * $effectiveRate;
        $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scope);
        $cleanupCost = $cleanupHours * $effectiveRate;
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scope);

        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $totalMaterialCost);

        return $settings->calculateOTDFromCOGS($cogsResult['cogs'], $scope);
    }

    /**
     * Calculate surface quantity from template dimensions and calculation method.
     */
    protected function calculateSurfaceQuantity(NestedTemplateSurface $surface): float
    {
        $length = (float) ($this->default_length ?? 0);
        $width = (float) ($this->default_width ?? 0);
        $height = (float) ($this->default_height ?? 8);
        $perimeter = 2 * ($length + $width);
        $floorSqft = $length * $width;

        return match ($surface->calculation_method) {
            'ratio' => match ($surface->surface_type) {
                'walls' => $perimeter * $height * (float) $surface->calculation_value,
                'ceiling', 'floor' => $floorSqft * (float) $surface->calculation_value,
                'trim' => $perimeter * (float) $surface->calculation_value,
                'doors' => (float) $surface->calculation_value,
                default => $floorSqft * (float) $surface->calculation_value,
            },
            'per_100sqft' => ($floorSqft / 100) * (float) $surface->calculation_value,
            'per_lnft' => $perimeter * (float) $surface->calculation_value,
            'fixed_count' => (float) $surface->calculation_value,
            'fixed_price' => 0, // No labor calculation for fixed price items
            default => 0,
        };
    }

    /**
     * Get the effective price based on pricing mode.
     * Uses margin-calculated price when enabled, manual fixed_price otherwise.
     */
    public function getEffectivePrice(?string $scopeType = null): float
    {
        if ($this->use_margin_pricing && ($this->estimated_hours > 0 || $this->material_cost > 0)) {
            return $this->getCalculatedPrice($scopeType);
        }

        return (float) $this->fixed_price;
    }

    /**
     * Get the formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return '$'.number_format($this->getEffectivePrice(), 2);
    }

    /**
     * Get what's included as a string.
     */
    public function getIncludedItemsAttribute(): string
    {
        $items = [];

        if ($this->includes_walls) {
            $items[] = 'Walls';
        }
        if ($this->includes_ceiling) {
            $items[] = 'Ceiling';
        }
        if ($this->includes_trim) {
            $items[] = 'Trim';
        }
        if ($this->includes_door) {
            $items[] = 'Door';
        }

        return empty($items) ? 'None' : implode(', ', $items);
    }

    /**
     * Get available categories.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_CLOSET => 'Closet',
            self::CATEGORY_BATHROOM => 'Bathroom',
            self::CATEGORY_UTILITY => 'Utility Room',
            self::CATEGORY_OTHER => 'Other',
        ];
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
            self::SCOPE_BOTH => 'Both',
        ];
    }
}
