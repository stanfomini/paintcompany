<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Production Rate model for painting industry estimates.
 *
 * Production rates define the speed (sqft/hr, lnft/hr, etc.) at which work can be completed.
 * The hourly_labor_rate field is a reference value; the PBP (Painting Business Pricing)
 * formula uses scope-specific rates from CompanySetting instead:
 * - Interior: $21/hr base wage × 1.35 payroll burden = ~$28.35 effective rate
 * - Exterior: $27/hr base wage × 1.35 payroll burden = ~$36.45 effective rate
 *
 * For PBP-based pricing, use EstimateTemplateService::calculatePBPPrice() or
 * TemplateBuilder::getTemplateCostBreakdown() instead of calculatePrice().
 */
class ProductionRate extends Model
{
    /** @use HasFactory<\Database\Factories\ProductionRateFactory> */
    use HasFactory;

    // Unit types (what we're measuring)
    public const UNIT_TYPE_SQFT = 'sqft';

    public const UNIT_TYPE_LNFT = 'lnft';

    public const UNIT_TYPE_UNIT = 'unit';

    public const UNIT_TYPE_HOUR = 'hour';

    public const UNIT_TYPES = [
        self::UNIT_TYPE_SQFT,
        self::UNIT_TYPE_LNFT,
        self::UNIT_TYPE_UNIT,
        self::UNIT_TYPE_HOUR,
    ];

    // Rate types (how the production_rate is expressed)
    public const RATE_TYPE_SQFT_HR = 'sqft_hr';

    public const RATE_TYPE_LNFT_HR = 'lnft_hr';

    public const RATE_TYPE_UNITS_HR = 'units_hr';

    public const RATE_TYPE_HOURS_PER_UNIT = 'hours_per_unit';

    public const RATE_TYPE_COST_PER_LNFT = 'cost_per_lnft';

    public const RATE_TYPES = [
        self::RATE_TYPE_SQFT_HR => 'sqft/hr',
        self::RATE_TYPE_LNFT_HR => 'lnft/hr',
        self::RATE_TYPE_UNITS_HR => 'units/hr',
        self::RATE_TYPE_HOURS_PER_UNIT => 'hrs/unit',
        self::RATE_TYPE_COST_PER_LNFT => '$/lnft',
    ];

    // Scopes (interior vs exterior)
    public const SCOPE_INTERIOR = 'interior';

    public const SCOPE_EXTERIOR = 'exterior';

    public const SCOPES = [
        self::SCOPE_INTERIOR => 'Interior',
        self::SCOPE_EXTERIOR => 'Exterior',
    ];

    // Interior surface categories
    public const CATEGORY_PREP = 'prep';

    public const CATEGORY_WALLS = 'walls';

    public const CATEGORY_CEILING = 'ceiling';

    public const CATEGORY_BASEBOARD = 'baseboard';

    public const CATEGORY_DOORS = 'doors';

    // Exterior surface categories
    public const CATEGORY_BODY = 'body';

    public const CATEGORY_EAVES = 'eaves';

    public const CATEGORY_FASCIA = 'fascia';

    public const CATEGORY_WINDOWS = 'windows';

    public const CATEGORY_TRIM = 'trim';

    public const CATEGORY_EXT_DOORS = 'exterior_doors';

    public const CATEGORY_WOOD_REPLACEMENT = 'wood_replacement';

    /**
     * Interior surface categories with labels.
     *
     * @var array<string, string>
     */
    public const INTERIOR_CATEGORIES = [
        self::CATEGORY_PREP => 'Prep Work',
        self::CATEGORY_WALLS => 'Walls',
        self::CATEGORY_CEILING => 'Ceiling',
        self::CATEGORY_BASEBOARD => 'Baseboard',
        self::CATEGORY_DOORS => 'Doors',
    ];

    /**
     * Exterior surface categories with labels.
     *
     * @var array<string, string>
     */
    public const EXTERIOR_CATEGORIES = [
        self::CATEGORY_PREP => 'Prep Work',
        self::CATEGORY_BODY => 'Body Application',
        self::CATEGORY_EAVES => 'Eaves',
        self::CATEGORY_FASCIA => 'Fascia',
        self::CATEGORY_WINDOWS => 'Windows',
        self::CATEGORY_TRIM => 'Other Trim',
        self::CATEGORY_EXT_DOORS => 'Doors',
        self::CATEGORY_WOOD_REPLACEMENT => 'Wood Replacement',
    ];

    protected $fillable = [
        'category_id',
        'scope',
        'surface_category',
        'spreadsheet_name',
        'name',
        'description',
        'unit_type',
        'production_rate',
        'rate_type',
        'hourly_labor_rate',
        'material_cost_per_unit',
        'coat_multipliers',
        'default_coats',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'production_rate' => 'decimal:2',
            'hourly_labor_rate' => 'decimal:2',
            'material_cost_per_unit' => 'decimal:2',
            'coat_multipliers' => 'array',
            'default_coats' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the category for this production rate.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductionRateCategory::class, 'category_id');
    }

    /**
     * Get the human-readable label for the unit type.
     */
    public function getUnitTypeLabelAttribute(): string
    {
        return match ($this->unit_type) {
            self::UNIT_TYPE_SQFT => 'Square Feet',
            self::UNIT_TYPE_LNFT => 'Linear Feet',
            self::UNIT_TYPE_UNIT => 'Unit',
            self::UNIT_TYPE_HOUR => 'Hour',
            default => $this->unit_type,
        };
    }

    /**
     * Scope to only include active production rates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by scope (interior/exterior).
     */
    public function scopeByScope(Builder $query, string $scope): Builder
    {
        return $query->where('scope', $scope);
    }

    /**
     * Scope to filter by surface category.
     */
    public function scopeBySurfaceCategory(Builder $query, string $category): Builder
    {
        return $query->where('surface_category', $category);
    }

    /**
     * Scope to filter interior rates.
     */
    public function scopeInterior(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_INTERIOR);
    }

    /**
     * Scope to filter exterior rates.
     */
    public function scopeExterior(Builder $query): Builder
    {
        return $query->where('scope', self::SCOPE_EXTERIOR);
    }

    /**
     * Get the rate type label.
     */
    public function getRateTypeLabelAttribute(): string
    {
        return self::RATE_TYPES[$this->rate_type] ?? $this->rate_type;
    }

    /**
     * Get the scope label.
     */
    public function getScopeLabelAttribute(): string
    {
        return self::SCOPES[$this->scope] ?? $this->scope ?? 'Unknown';
    }

    /**
     * Get the surface category label.
     */
    public function getSurfaceCategoryLabelAttribute(): string
    {
        $categories = $this->scope === self::SCOPE_INTERIOR
            ? self::INTERIOR_CATEGORIES
            : self::EXTERIOR_CATEGORIES;

        return $categories[$this->surface_category] ?? $this->surface_category ?? 'Unknown';
    }

    /**
     * Get categories for a given scope.
     *
     * @return array<string, string>
     */
    public static function getCategoriesForScope(string $scope): array
    {
        return $scope === self::SCOPE_INTERIOR
            ? self::INTERIOR_CATEGORIES
            : self::EXTERIOR_CATEGORIES;
    }

    /**
     * Calculate labor hours based on rate type.
     *
     * Different rate types require different calculations:
     * - sqft_hr: quantity / rate = hours
     * - lnft_hr: quantity / rate = hours
     * - units_hr: quantity / rate = hours
     * - hours_per_unit: quantity * rate = hours (rate IS hours per unit)
     * - cost_per_lnft: N/A (returns 0, this is for pricing)
     */
    public function calculateHoursForQuantity(float $quantity, ?int $coats = null): float
    {
        $coats = $coats ?? $this->default_coats ?? 1;
        $coatMultiplier = $this->getCoatMultiplier($coats);

        return match ($this->rate_type) {
            self::RATE_TYPE_HOURS_PER_UNIT => $quantity * $this->production_rate * $coatMultiplier,
            self::RATE_TYPE_COST_PER_LNFT => 0, // Cost-based, not hours-based
            default => ($quantity / $this->production_rate) * $coatMultiplier,
        };
    }

    /**
     * Calculate cost for cost-based rate types (wood replacement).
     */
    public function calculateCostForQuantity(float $quantity): float
    {
        if ($this->rate_type !== self::RATE_TYPE_COST_PER_LNFT) {
            return 0;
        }

        return $quantity * $this->production_rate;
    }

    /**
     * Get the coat multiplier for a given number of coats.
     */
    public function getCoatMultiplier(int $coats): float
    {
        $multipliers = $this->coat_multipliers ?? [];

        return (float) ($multipliers[(string) $coats] ?? $coats);
    }

    /**
     * Calculate the price for a given quantity and number of coats.
     *
     * NOTE: This method uses the production rate's hourly_labor_rate, which is a simplified
     * calculation. For PBP (Painting Business Pricing) compliant estimates, use
     * EstimateTemplateService::calculatePBPPrice() which applies:
     * - Scope-specific hourly wages from CompanySetting
     * - Payroll burden multiplier
     * - Cleanup hours and cost
     * - Sundries cost
     * - Profit, overhead, and scope-specific markup margins
     *
     * Formula: ((quantity / production_rate) * hourly_labor_rate + (quantity * material_cost_per_unit)) * coat_multiplier
     */
    public function calculatePrice(float $quantity, ?int $coats = null): float
    {
        $coats = $coats ?? $this->default_coats ?? 1;
        $coatMultiplier = $this->getCoatMultiplier($coats);

        $laborCost = ($quantity / $this->production_rate) * $this->hourly_labor_rate;
        $materialCost = $quantity * $this->material_cost_per_unit;

        return ($laborCost + $materialCost) * $coatMultiplier;
    }

    /**
     * Calculate labor hours for a given quantity and number of coats.
     *
     * This is used by the PBP formula to determine hours, which are then
     * multiplied by scope-specific rates from CompanySetting.
     */
    public function calculateLaborHours(float $quantity, ?int $coats = null): float
    {
        $coats = $coats ?? $this->default_coats ?? 1;
        $coatMultiplier = $this->getCoatMultiplier($coats);

        return ($quantity / $this->production_rate) * $coatMultiplier;
    }
}
