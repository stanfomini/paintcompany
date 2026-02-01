<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'company_email',
        'company_phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip_code',
        'default_tax_rate',
        'default_payment_terms_days',
        'estimate_validity_days',
        'logo_path',
        // Labor Configuration
        'base_hourly_wage',
        'interior_hourly_wage',
        'exterior_hourly_wage',
        'payroll_burden_multiplier',
        // Margins & Markup
        'net_profit_margin_percent',
        'overhead_percent',
        'marketing_percent',
        'sales_percent',
        'production_management_percent',
        'markup_percent',
        'interior_markup_percent',
        'exterior_markup_percent',
        // Defaults
        'default_deposit_percent',
        'default_sales_discount_percent',
        'material_tax_rate',
        'default_interior_sqft_rate',
        'default_exterior_sqft_rate',
        // JSON fields
        'paint_catalog',
        'trim_widths',
        'warranty_options',
        // Production Settings
        'cleanup_hours_ratio',
        'interior_cleanup_hours_ratio',
        'exterior_cleanup_hours_ratio',
        'sundries_cost_per_prep_hour',
        'interior_sundries_cost_per_hour',
        'exterior_sundries_cost_per_hour',
        'spray_paint_coverage_multiplier',
        'brush_roll_coverage_multiplier',
        // Recycle/Misc Fees
        'recycle_fee_per_gallon',
        'wood_replacement_minimum',
    ];

    protected function casts(): array
    {
        return [
            'default_tax_rate' => 'decimal:2',
            'default_payment_terms_days' => 'integer',
            'estimate_validity_days' => 'integer',
            // Labor Configuration
            'base_hourly_wage' => 'decimal:2',
            'interior_hourly_wage' => 'decimal:2',
            'exterior_hourly_wage' => 'decimal:2',
            'payroll_burden_multiplier' => 'decimal:2',
            // Margins & Markup
            'net_profit_margin_percent' => 'decimal:2',
            'overhead_percent' => 'decimal:2',
            'marketing_percent' => 'decimal:2',
            'sales_percent' => 'decimal:2',
            'production_management_percent' => 'decimal:2',
            'markup_percent' => 'decimal:2',
            'interior_markup_percent' => 'decimal:2',
            'exterior_markup_percent' => 'decimal:2',
            // Defaults
            'default_deposit_percent' => 'decimal:2',
            'default_sales_discount_percent' => 'decimal:2',
            'material_tax_rate' => 'decimal:2',
            'default_interior_sqft_rate' => 'decimal:4',
            'default_exterior_sqft_rate' => 'decimal:4',
            // JSON fields
            'paint_catalog' => 'array',
            'trim_widths' => 'array',
            'warranty_options' => 'array',
            // Production Settings
            'cleanup_hours_ratio' => 'decimal:2',
            'interior_cleanup_hours_ratio' => 'decimal:2',
            'exterior_cleanup_hours_ratio' => 'decimal:2',
            'sundries_cost_per_prep_hour' => 'decimal:2',
            'interior_sundries_cost_per_hour' => 'decimal:2',
            'exterior_sundries_cost_per_hour' => 'decimal:2',
            'spray_paint_coverage_multiplier' => 'decimal:2',
            'brush_roll_coverage_multiplier' => 'decimal:2',
            // Recycle/Misc Fees
            'recycle_fee_per_gallon' => 'decimal:2',
            'wood_replacement_minimum' => 'decimal:2',
        ];
    }

    /**
     * Get the singleton company settings instance.
     * Creates a default record if none exists.
     */
    public static function getInstance(): self
    {
        return static::firstOrCreate([], [
            'company_name' => config('app.name', 'My Company'),
            'paint_catalog' => self::getDefaultPaintCatalog(),
            'trim_widths' => self::getDefaultTrimWidths(),
            'warranty_options' => self::getDefaultWarrantyOptions(),
            // Scope-specific defaults from PBP spreadsheet
            'interior_hourly_wage' => 21.00,
            'exterior_hourly_wage' => 27.00,
            'interior_markup_percent' => 50.00,
            'exterior_markup_percent' => 41.00,
            'interior_cleanup_hours_ratio' => 16.00,
            'exterior_cleanup_hours_ratio' => 20.00,
            'interior_sundries_cost_per_hour' => 3.30,
            'exterior_sundries_cost_per_hour' => 1.00,
            // Production settings
            'spray_paint_coverage_multiplier' => 2.00,
            'brush_roll_coverage_multiplier' => 1.30,
        ]);
    }

    /**
     * Get default paint catalog with common paint brands, prices, and coverage.
     * Coverage is in sqft per gallon (standard coverage, adjusted by application method).
     *
     * @return array<string, array<string, array{price: float, coverage_sqft: int}>>
     */
    public static function getDefaultPaintCatalog(): array
    {
        return [
            'sherwin_williams' => [
                'duration' => ['price' => 75.00, 'coverage_sqft' => 400],
                'emerald' => ['price' => 95.00, 'coverage_sqft' => 400],
                'cashmere' => ['price' => 65.00, 'coverage_sqft' => 400],
                'superpaint' => ['price' => 55.00, 'coverage_sqft' => 350],
                'promar_200' => ['price' => 35.00, 'coverage_sqft' => 350],
                'exterior_duration' => ['price' => 85.00, 'coverage_sqft' => 350],
                'exterior_superpaint' => ['price' => 60.00, 'coverage_sqft' => 300],
            ],
            'benjamin_moore' => [
                'regal_select' => ['price' => 70.00, 'coverage_sqft' => 400],
                'aura' => ['price' => 90.00, 'coverage_sqft' => 400],
                'ben' => ['price' => 45.00, 'coverage_sqft' => 400],
                'natura' => ['price' => 85.00, 'coverage_sqft' => 400],
                'exterior_aura' => ['price' => 95.00, 'coverage_sqft' => 350],
                'exterior_regal' => ['price' => 75.00, 'coverage_sqft' => 350],
            ],
            'ppg' => [
                'diamond' => ['price' => 50.00, 'coverage_sqft' => 400],
                'timeless' => ['price' => 70.00, 'coverage_sqft' => 400],
                'manor_hall' => ['price' => 55.00, 'coverage_sqft' => 350],
            ],
        ];
    }

    /**
     * Get default trim widths in inches.
     *
     * @return array<string, float>
     */
    public static function getDefaultTrimWidths(): array
    {
        return [
            'baseboard' => 4.0,
            'crown_molding' => 6.0,
            'door_casing' => 3.0,
            'window_casing' => 3.0,
            'chair_rail' => 2.5,
            'wainscoting' => 36.0,
            'quarter_round' => 0.75,
            'shoe_molding' => 0.75,
        ];
    }

    /**
     * Get default warranty options.
     *
     * @return array<int, array{years: int, name: string, description: string, price_multiplier: float}>
     */
    public static function getDefaultWarrantyOptions(): array
    {
        return [
            [
                'years' => 1,
                'name' => 'Standard Warranty',
                'description' => '1-year warranty covering workmanship defects',
                'price_multiplier' => 1.0,
            ],
            [
                'years' => 3,
                'name' => 'Extended Warranty',
                'description' => '3-year warranty covering workmanship and paint failure',
                'price_multiplier' => 1.05,
            ],
            [
                'years' => 5,
                'name' => 'Premium Warranty',
                'description' => '5-year comprehensive warranty including touch-ups',
                'price_multiplier' => 1.10,
            ],
        ];
    }

    /**
     * Get the full formatted address.
     */
    public function getFullAddressAttribute(): ?string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
        ]);

        if (empty($parts)) {
            return null;
        }

        $cityStateZip = array_filter([
            $this->city,
            $this->state ? $this->state.' '.$this->zip_code : $this->zip_code,
        ]);

        if (! empty($cityStateZip)) {
            $parts[] = implode(', ', $cityStateZip);
        }

        return implode("\n", $parts);
    }

    /**
     * Get the effective hourly rate (base wage with payroll burden).
     */
    protected function effectiveHourlyRate(): Attribute
    {
        return Attribute::get(function (): float {
            $baseWage = (float) ($this->base_hourly_wage ?? 25.00);
            $multiplier = (float) ($this->payroll_burden_multiplier ?? 1.35);

            return $baseWage * $multiplier;
        });
    }

    /**
     * Get paint price from catalog by brand and type.
     */
    public function getPaintPrice(string $brand, string $type): ?float
    {
        $details = $this->getPaintDetails($brand, $type);

        return $details['price'] ?? null;
    }

    /**
     * Get paint coverage (sqft per gallon) from catalog by brand and type.
     */
    public function getPaintCoverage(string $brand, string $type): ?int
    {
        $details = $this->getPaintDetails($brand, $type);

        return $details['coverage_sqft'] ?? null;
    }

    /**
     * Get full paint details (price and coverage) from catalog.
     *
     * @return array{price: float, coverage_sqft: int}|null
     */
    public function getPaintDetails(string $brand, string $type): ?array
    {
        $catalog = $this->paint_catalog ?? self::getDefaultPaintCatalog();

        $brand = strtolower(str_replace([' ', '-'], '_', $brand));
        $type = strtolower(str_replace([' ', '-'], '_', $type));

        if (isset($catalog[$brand][$type])) {
            $paint = $catalog[$brand][$type];

            // Handle both old format (float) and new format (array)
            if (is_array($paint)) {
                return [
                    'price' => (float) $paint['price'],
                    'coverage_sqft' => (int) $paint['coverage_sqft'],
                ];
            }

            // Legacy format: just price, use default coverage
            return [
                'price' => (float) $paint,
                'coverage_sqft' => 400, // Default coverage
            ];
        }

        return null;
    }

    /**
     * Calculate gallons of paint needed based on sqft, coats, and application method.
     *
     * @param  string  $applicationMethod  'spray' or 'brush_roll'
     * @return array{gallons: float, gallons_rounded: int, coverage_used: float}
     */
    public function calculateGallonsNeeded(
        float $sqft,
        int $baseCoverageSqft,
        int $coats = 2,
        string $applicationMethod = 'brush_roll'
    ): array {
        // Get coverage multiplier based on application method
        $multiplier = match ($applicationMethod) {
            'spray' => (float) ($this->spray_paint_coverage_multiplier ?? 2.0),
            'brush_roll' => (float) ($this->brush_roll_coverage_multiplier ?? 1.3),
            default => 1.0,
        };

        // Adjusted coverage = base coverage × method multiplier
        $adjustedCoverage = $baseCoverageSqft * $multiplier;

        // Total sqft to cover = sqft × coats
        $totalSqft = $sqft * $coats;

        // Gallons needed = total sqft / adjusted coverage
        $gallons = $totalSqft / $adjustedCoverage;

        return [
            'gallons' => round($gallons, 2),
            'gallons_rounded' => (int) ceil($gallons), // Round up for ordering
            'coverage_used' => round($adjustedCoverage, 0),
        ];
    }

    /**
     * Calculate full paint cost including gallons, paint price, and recycle fee.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total_cost: float}
     */
    public function calculatePaintCost(
        float $sqft,
        string $brand,
        string $type,
        int $coats = 2,
        string $applicationMethod = 'brush_roll'
    ): ?array {
        $paintDetails = $this->getPaintDetails($brand, $type);

        if (! $paintDetails) {
            return null;
        }

        $gallonCalc = $this->calculateGallonsNeeded(
            $sqft,
            $paintDetails['coverage_sqft'],
            $coats,
            $applicationMethod
        );

        $paintCost = $gallonCalc['gallons_rounded'] * $paintDetails['price'];
        $recycleFee = $gallonCalc['gallons_rounded'] * (float) ($this->recycle_fee_per_gallon ?? 0.54);

        return [
            'gallons' => $gallonCalc['gallons'],
            'gallons_rounded' => $gallonCalc['gallons_rounded'],
            'coverage_used' => $gallonCalc['coverage_used'],
            'price_per_gallon' => $paintDetails['price'],
            'paint_cost' => round($paintCost, 2),
            'recycle_fee' => round($recycleFee, 2),
            'total_cost' => round($paintCost + $recycleFee, 2),
        ];
    }

    /**
     * Get all available paint brands from catalog.
     *
     * @return array<string>
     */
    public function getAvailablePaintBrands(): array
    {
        $catalog = $this->paint_catalog ?? self::getDefaultPaintCatalog();

        return array_keys($catalog);
    }

    /**
     * Get all paint types for a given brand.
     *
     * @return array<string>
     */
    public function getPaintTypesForBrand(string $brand): array
    {
        $catalog = $this->paint_catalog ?? self::getDefaultPaintCatalog();

        $brand = strtolower(str_replace([' ', '-'], '_', $brand));

        if (isset($catalog[$brand])) {
            return array_keys($catalog[$brand]);
        }

        return [];
    }

    /**
     * Get trim width by trim type.
     */
    public function getTrimWidth(string $trimType): ?float
    {
        $widths = $this->trim_widths ?? self::getDefaultTrimWidths();

        $trimType = strtolower(str_replace([' ', '-'], '_', $trimType));

        if (isset($widths[$trimType])) {
            return (float) $widths[$trimType];
        }

        return null;
    }

    /**
     * Calculate price with markup percentage applied.
     */
    public function calculateMarkupPrice(float $cost): float
    {
        $markupPercent = (float) ($this->markup_percent ?? 45.00);

        return $cost * (1 + $markupPercent / 100);
    }

    /**
     * Calculate price with profit margin (inverse calculation for desired margin).
     */
    public function calculateWithProfitMargin(float $cost): float
    {
        $marginPercent = (float) ($this->net_profit_margin_percent ?? 30.00);

        // Prevent division by zero
        if ($marginPercent >= 100) {
            return $cost;
        }

        return $cost / (1 - $marginPercent / 100);
    }

    /**
     * Calculate price with all margins applied sequentially: profit → overhead → markup.
     * This follows the PBP spreadsheet formula for full price calculation.
     */
    public function calculateWithMargins(float $baseCost): float
    {
        $profitPercent = (float) ($this->net_profit_margin_percent ?? 30.00);
        $overheadPercent = (float) ($this->overhead_percent ?? 12.00);
        $markupPercent = (float) ($this->markup_percent ?? 45.00);

        $withProfit = $baseCost * (1 + $profitPercent / 100);
        $withOverhead = $withProfit * (1 + $overheadPercent / 100);
        $withMarkup = $withOverhead * (1 + $markupPercent / 100);

        return round($withMarkup, 2);
    }

    /**
     * Calculate labor cost from hours using effective hourly rate.
     */
    public function calculateLaborCost(float $hours): float
    {
        return round($hours * $this->effective_hourly_rate, 2);
    }

    /**
     * Calculate price from production rate using the full formula:
     * (quantity / production_rate) × effective_hourly_rate × coats + materials
     * Then apply profit, overhead, and markup margins.
     */
    public function calculateFromProductionRate(
        float $quantity,
        float $productionRate,
        float $materialCost,
        int $coats = 1
    ): float {
        // Prevent division by zero
        if ($productionRate <= 0) {
            return $materialCost;
        }

        // Hours = quantity / production_rate
        $hours = $quantity / $productionRate;

        // Labor cost = hours × effective_hourly_rate × coats
        $laborCost = $hours * $this->effective_hourly_rate * $coats;

        // Material cost adjusted for coats
        $totalMaterialCost = $materialCost * $coats;

        // Base cost = labor + materials
        $baseCost = $laborCost + $totalMaterialCost;

        // Apply all margins
        return $this->calculateWithMargins($baseCost);
    }

    /**
     * Get warranty options with defaults if not set.
     *
     * @return array<int, array{years: int, name: string, description: string, price_multiplier: float}>
     */
    public function getWarrantyOptions(): array
    {
        return $this->warranty_options ?? self::getDefaultWarrantyOptions();
    }

    /**
     * Get the hourly wage for a specific scope type.
     * Interior: $21/hr, Exterior: $27/hr (based on PBP spreadsheet).
     */
    public function getHourlyWageForScope(string $scopeType): float
    {
        return match ($scopeType) {
            'interior' => (float) ($this->interior_hourly_wage ?? 21.00),
            'exterior' => (float) ($this->exterior_hourly_wage ?? 27.00),
            default => (float) ($this->base_hourly_wage ?? 25.00),
        };
    }

    /**
     * Get the effective hourly rate for a specific scope type (wage × payroll burden).
     */
    public function getEffectiveHourlyRateForScope(string $scopeType): float
    {
        $baseWage = $this->getHourlyWageForScope($scopeType);
        $multiplier = (float) ($this->payroll_burden_multiplier ?? 1.35);

        return $baseWage * $multiplier;
    }

    /**
     * Get the markup percentage for a specific scope type.
     * Interior: 50%, Exterior: 41% (based on PBP spreadsheet).
     */
    public function getMarkupPercentForScope(string $scopeType): float
    {
        return match ($scopeType) {
            'interior' => (float) ($this->interior_markup_percent ?? 50.00),
            'exterior' => (float) ($this->exterior_markup_percent ?? 41.00),
            default => (float) ($this->markup_percent ?? 45.00),
        };
    }

    /**
     * Get the cleanup hours ratio for a specific scope type.
     * Interior: 16 hrs work per 1 hr cleanup, Exterior: 20 hrs.
     */
    public function getCleanupHoursRatioForScope(string $scopeType): float
    {
        return match ($scopeType) {
            'interior' => (float) ($this->interior_cleanup_hours_ratio ?? 16.00),
            'exterior' => (float) ($this->exterior_cleanup_hours_ratio ?? 20.00),
            default => (float) ($this->cleanup_hours_ratio ?? 16.00),
        };
    }

    /**
     * Get the sundries cost per hour for a specific scope type.
     * Interior: $3.30/hr prep, Exterior: $1.00/hr.
     */
    public function getSundriesCostForScope(string $scopeType): float
    {
        return match ($scopeType) {
            'interior' => (float) ($this->interior_sundries_cost_per_hour ?? 3.30),
            'exterior' => (float) ($this->exterior_sundries_cost_per_hour ?? 1.00),
            default => (float) ($this->sundries_cost_per_prep_hour ?? 3.30),
        };
    }

    /**
     * Calculate labor cost from hours for a specific scope type.
     */
    public function calculateLaborCostForScope(float $hours, string $scopeType): float
    {
        return round($hours * $this->getEffectiveHourlyRateForScope($scopeType), 2);
    }

    /**
     * Calculate cleanup hours based on total labor hours and scope type.
     */
    public function calculateCleanupHours(float $laborHours, string $scopeType): float
    {
        $ratio = $this->getCleanupHoursRatioForScope($scopeType);

        return round($laborHours / $ratio, 2);
    }

    /**
     * Calculate sundries cost based on prep hours and scope type.
     */
    public function calculateSundriesCost(float $prepHours, string $scopeType): float
    {
        $costPerHour = $this->getSundriesCostForScope($scopeType);

        return round($prepHours * $costPerHour, 2);
    }

    /**
     * Calculate price with all margins applied for a specific scope type.
     * Uses scope-specific markup percentage.
     */
    public function calculateWithMarginsForScope(float $baseCost, string $scopeType): float
    {
        $profitPercent = (float) ($this->net_profit_margin_percent ?? 30.00);
        $overheadPercent = (float) ($this->overhead_percent ?? 12.00);
        $markupPercent = $this->getMarkupPercentForScope($scopeType);

        $withProfit = $baseCost * (1 + $profitPercent / 100);
        $withOverhead = $withProfit * (1 + $overheadPercent / 100);
        $withMarkup = $withOverhead * (1 + $markupPercent / 100);

        return round($withMarkup, 2);
    }

    /**
     * Get the complete PBP pricing breakdown for a scope type.
     *
     * @return array{
     *     hourly_wage: float,
     *     effective_hourly_rate: float,
     *     markup_percent: float,
     *     cleanup_hours_ratio: float,
     *     sundries_cost_per_hour: float,
     *     profit_percent: float,
     *     overhead_percent: float
     * }
     */
    public function getPricingConfigForScope(string $scopeType): array
    {
        return [
            'hourly_wage' => $this->getHourlyWageForScope($scopeType),
            'effective_hourly_rate' => $this->getEffectiveHourlyRateForScope($scopeType),
            'markup_percent' => $this->getMarkupPercentForScope($scopeType),
            'cleanup_hours_ratio' => $this->getCleanupHoursRatioForScope($scopeType),
            'sundries_cost_per_hour' => $this->getSundriesCostForScope($scopeType),
            'profit_percent' => (float) ($this->net_profit_margin_percent ?? 30.00),
            'overhead_percent' => (float) ($this->overhead_percent ?? 12.00),
        ];
    }
}
