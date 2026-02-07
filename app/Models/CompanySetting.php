<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanySetting extends Model
{
    use HasFactory;

    /**
     * Static in-memory cache for the singleton instance.
     * Prevents repeated DB queries within a single request.
     */
    protected static ?self $resolvedInstance = null;

    protected $fillable = [
        'company_name',
        'company_email',
        'company_phone',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip_code',
        'timezone',
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
        'margin_percent',
        'interior_markup_percent',
        'interior_margin_percent',
        'exterior_markup_percent',
        'exterior_margin_percent',
        // Defaults
        'default_deposit_percent',
        'accounting_mode',
        'default_sales_discount_percent',
        'material_tax_rate',
        'labor_tax_rate',
        'default_interior_sqft_rate',
        'default_exterior_sqft_rate',
        // JSON fields
        'paint_catalog',
        'default_paint_products_by_category',
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
        // Crew Settings
        'default_required_crew_size',
        'total_painters',
        // Payment Integrations
        'stripe_connect_enabled',
        'stripe_ach_enabled',
        'increase_enabled',
        'increase_environment',
        'increase_access_token',
        'increase_oauth_state',
        'payment_split_enabled',
        'payment_split_config',
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
            'margin_percent' => 'decimal:2',
            'interior_markup_percent' => 'decimal:2',
            'interior_margin_percent' => 'decimal:2',
            'exterior_markup_percent' => 'decimal:2',
            'exterior_margin_percent' => 'decimal:2',
            // Defaults
            'default_deposit_percent' => 'decimal:2',
            'default_sales_discount_percent' => 'decimal:2',
            'material_tax_rate' => 'decimal:2',
            'labor_tax_rate' => 'decimal:2',
            'default_interior_sqft_rate' => 'decimal:4',
            'default_exterior_sqft_rate' => 'decimal:4',
            // JSON fields
            'paint_catalog' => 'array',
            'default_paint_products_by_category' => 'array',
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
            // Crew Settings
            'default_required_crew_size' => 'integer',
            'total_painters' => 'integer',
            // Payment Integrations
            'stripe_connect_enabled' => 'boolean',
            'stripe_ach_enabled' => 'boolean',
            'increase_enabled' => 'boolean',
            'increase_access_token' => 'encrypted',
            'payment_split_enabled' => 'boolean',
            'payment_split_config' => 'array',
        ];
    }

    /**
     * Get the singleton company settings instance.
     * Creates a default record if none exists.
     * Uses static in-memory cache to avoid repeated DB queries per request.
     */
    public static function getInstance(): self
    {
        return static::$resolvedInstance ??= static::firstOrCreate([], [
            'company_name' => config('app.name', 'My Company'),
            'paint_catalog' => self::getDefaultPaintCatalog(),
            'trim_widths' => self::getDefaultTrimWidths(),
            'warranty_options' => self::getDefaultWarrantyOptions(),
            // Core pricing settings
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.35,
            'net_profit_margin_percent' => 30.00,
            'overhead_percent' => 12.00,
            'marketing_percent' => 8.00,
            'sales_percent' => 7.00,
            'production_management_percent' => 7.00,
            'recycle_fee_per_gallon' => 0.54,
            'material_tax_rate' => 7.50,
            'labor_tax_rate' => 0.00,
            'default_tax_rate' => 0.00,
            'default_deposit_percent' => 30.00,
            'default_interior_sqft_rate' => 4.50,
            'default_exterior_sqft_rate' => 3.50,
            'estimate_validity_days' => 30,
            // Scope-specific defaults from PBP spreadsheet
            'interior_hourly_wage' => 21.00,
            'exterior_hourly_wage' => 27.00,
            'interior_markup_percent' => 50.00,
            'exterior_markup_percent' => 41.00,
            'interior_margin_percent' => 50.00,
            'exterior_margin_percent' => 41.00,
            'interior_cleanup_hours_ratio' => 16.00,
            'exterior_cleanup_hours_ratio' => 20.00,
            'interior_sundries_cost_per_hour' => 3.30,
            'exterior_sundries_cost_per_hour' => 1.00,
            // Production settings
            'spray_paint_coverage_multiplier' => 2.00,
            'brush_roll_coverage_multiplier' => 1.30,
            // Crew & Dispatch settings
            'default_required_crew_size' => 2,
            'total_painters' => 4,
        ]);
    }

    /**
     * Clear the static instance cache.
     * Called automatically on save/delete to ensure freshness within the same request.
     */
    public static function clearInstanceCache(): void
    {
        static::$resolvedInstance = null;
    }

    /**
     * Boot the model and register events to clear the cache on changes.
     */
    protected static function booted(): void
    {
        static::saved(function () {
            static::clearInstanceCache();
        });

        static::deleted(function () {
            static::clearInstanceCache();
        });
    }

    /**
     * Get the allocations for this company setting.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CompanyAllocation::class);
    }

    /**
     * Get active allocations that reduce profit.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyAllocation>
     */
    public function getActiveAllocations(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->allocations()
            ->active()
            ->reducesProfit()
            ->ordered()
            ->get();
    }

    /**
     * Get all active allocations (including informational).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CompanyAllocation>
     */
    public function getAllAllocations(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->allocations()
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Calculate total allocations for a job.
     *
     * @return array{total: float, details: array}
     */
    public function calculateAllocations(float $customerPrice, float $laborHours = 0): array
    {
        $allocations = $this->getActiveAllocations();
        $details = [];
        $total = 0;

        foreach ($allocations as $allocation) {
            $amount = $allocation->calculateAmount($customerPrice, $laborHours);
            $details[] = [
                'id' => $allocation->id,
                'name' => $allocation->name,
                'slug' => $allocation->slug,
                'type' => $allocation->type,
                'type_label' => $allocation->type_label,
                'value' => (float) $allocation->value,
                'formatted_value' => $allocation->formatted_value,
                'amount' => $amount,
                'is_system' => $allocation->is_system,
            ];
            $total += $amount;
        }

        return [
            'total' => round($total, 2),
            'details' => $details,
        ];
    }

    /**
     * Ensure system allocations exist for this company setting.
     * Creates them if they don't exist.
     */
    public function ensureSystemAllocations(): void
    {
        $systemAllocations = [
            [
                'name' => 'Marketing',
                'slug' => 'marketing',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => (float) $this->marketing_percent ?: 8.00,
                'is_system' => true,
                'is_informational' => false,
                'description' => 'Marketing and advertising budget',
                'sort_order' => 1,
            ],
            [
                'name' => 'Sales',
                'slug' => 'sales',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => (float) $this->sales_percent ?: 7.00,
                'is_system' => true,
                'is_informational' => false,
                'description' => 'Sales commission and compensation',
                'sort_order' => 2,
            ],
            [
                'name' => 'Production Management',
                'slug' => 'production_management',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => (float) $this->production_management_percent ?: 7.00,
                'is_system' => true,
                'is_informational' => false,
                'description' => 'Production oversight and coordination',
                'sort_order' => 3,
            ],
            [
                'name' => 'General Overhead',
                'slug' => 'general_overhead',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => (float) $this->overhead_percent ?: 12.00,
                'is_system' => true,
                'is_informational' => true,
                'description' => 'Rent, utilities, insurance (informational only)',
                'sort_order' => 10,
            ],
        ];

        foreach ($systemAllocations as $allocationData) {
            $this->allocations()->updateOrCreate(
                ['slug' => $allocationData['slug']],
                $allocationData
            );
        }
    }

    /**
     * Seed smart default conditional allocations.
     *
     * Creates 14 practical business allocations that a painting company
     * commonly needs. All are created inactive so the user can review
     * and enable what they need.
     */
    public function seedDefaultAllocations(): void
    {
        $defaults = [
            [
                'name' => 'Credit Card Processing Fee',
                'slug' => 'credit_card_processing_fee',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_informational' => false,
                'description' => 'Card processing fee (Stripe/credit card payments)',
                'sort_order' => 20,
                'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'in', 'value' => ['credit_card', 'stripe']],
                ],
                'conditions_logic' => 'any',
            ],
            [
                'name' => 'ACH Processing Fee',
                'slug' => 'ach_processing_fee',
                'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
                'value' => 5.00,
                'is_informational' => false,
                'description' => 'Bank transfer processing fee',
                'sort_order' => 21,
                'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'ach'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Agent Referral Commission',
                'slug' => 'agent_referral_commission',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 5.00,
                'is_informational' => false,
                'description' => 'Commission for agent-referred jobs',
                'sort_order' => 22,
                'conditions' => [
                    ['field' => 'has_agent_referral', 'operator' => 'equals', 'value' => true],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Repeat Customer Loyalty Cost',
                'slug' => 'repeat_customer_loyalty_cost',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 2.00,
                'is_informational' => true,
                'description' => 'Tracks loyalty discount cost for returning customers',
                'sort_order' => 23,
                'conditions' => [
                    ['field' => 'is_repeat_customer', 'operator' => 'equals', 'value' => true],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Exterior Equipment Reserve',
                'slug' => 'exterior_equipment_reserve',
                'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
                'value' => 3.50,
                'is_informational' => false,
                'description' => 'Ladders, sprayers, power washers for exterior work',
                'sort_order' => 24,
                'conditions' => [
                    ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Interior Protection & Supplies',
                'slug' => 'interior_protection_supplies',
                'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
                'value' => 1.50,
                'is_informational' => false,
                'description' => 'Drop cloths, tape, plastic sheeting for interior',
                'sort_order' => 25,
                'conditions' => [
                    ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'interior'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Small Job Minimum Overhead',
                'slug' => 'small_job_minimum_overhead',
                'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
                'value' => 75.00,
                'is_informational' => false,
                'description' => 'Fixed overhead for small jobs under $1,500',
                'sort_order' => 26,
                'conditions' => [
                    ['field' => 'job_price', 'operator' => 'less_than', 'value' => '1500'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Large Job Efficiency Credit',
                'slug' => 'large_job_efficiency_credit',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 0.00,
                'is_informational' => true,
                'description' => 'Tracks efficiency gains on large jobs over $15,000',
                'sort_order' => 27,
                'conditions' => [
                    ['field' => 'job_price', 'operator' => 'greater_than', 'value' => '15000'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Winter Season Premium',
                'slug' => 'winter_season_premium',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_informational' => true,
                'description' => 'Tracks seasonal cost impact during winter months',
                'sort_order' => 28,
                'conditions' => [
                    ['field' => 'season', 'operator' => 'equals', 'value' => 'winter'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Heavy Prep Equipment Wear',
                'slug' => 'heavy_prep_equipment_wear',
                'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
                'value' => 2.00,
                'is_informational' => false,
                'description' => 'Extra equipment wear for complex substrates (peeling, masonry)',
                'sort_order' => 29,
                'conditions' => [
                    ['field' => 'has_complex_substrate', 'operator' => 'equals', 'value' => true],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Spray Equipment & Materials',
                'slug' => 'spray_equipment_materials',
                'type' => CompanyAllocation::TYPE_PER_LABOR_HOUR,
                'value' => 4.00,
                'is_informational' => false,
                'description' => 'Spray equipment maintenance, tips, and masking materials',
                'sort_order' => 30,
                'conditions' => [
                    ['field' => 'has_spray_application', 'operator' => 'equals', 'value' => true],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Multi-Crew Coordination',
                'slug' => 'multi_crew_coordination',
                'type' => CompanyAllocation::TYPE_FIXED_PER_JOB,
                'value' => 150.00,
                'is_informational' => false,
                'description' => 'Coordination overhead for jobs requiring 4+ crew members',
                'sort_order' => 31,
                'conditions' => [
                    ['field' => 'crew_size', 'operator' => 'greater_than', 'value' => '3'],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Subcontractor Management Fee',
                'slug' => 'subcontractor_management_fee',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 2.00,
                'is_informational' => false,
                'description' => 'Oversight cost when subcontractors are used',
                'sort_order' => 32,
                'conditions' => [
                    ['field' => 'has_subcontractor', 'operator' => 'equals', 'value' => true],
                ],
                'conditions_logic' => 'all',
            ],
            [
                'name' => 'Lead Platform Cost Recovery',
                'slug' => 'lead_platform_cost_recovery',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 4.00,
                'is_informational' => true,
                'description' => 'Tracks cost of paid lead platforms (Google Ads, HomeAdvisor, Thumbtack)',
                'sort_order' => 33,
                'conditions' => [
                    ['field' => 'lead_source', 'operator' => 'in', 'value' => ['google_ads', 'home_advisor', 'thumbtack']],
                ],
                'conditions_logic' => 'any',
            ],
        ];

        foreach ($defaults as $data) {
            $this->allocations()->updateOrCreate(
                ['slug' => $data['slug']],
                array_merge($data, [
                    'is_active' => false,
                    'is_system' => false,
                ])
            );
        }
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
     *
     * Uses the PBP formula: OTD = COGS / (margin% / 100)
     * Where margin% represents the COGS percentage of the sale price.
     */
    public function calculateWithProfitMargin(float $cost): float
    {
        $marginPercent = (float) ($this->net_profit_margin_percent ?? 30.00);

        // Prevent division by zero
        if ($marginPercent <= 0) {
            return $cost;
        }

        return round($cost / ($marginPercent / 100), 2);
    }

    /**
     * Calculate OTD (Out The Door) price from COGS using simple markup.
     *
     * @deprecated Use calculateOTD() instead for margin-based pricing.
     *
     * IMPORTANT: This is the ONLY margin calculation used for customer pricing.
     * The formula is: OTD = COGS × (1 + markup%)
     *
     * The other margin percentages (profit, overhead, marketing, sales, production_management)
     * are for a SEPARATE business analysis tool to help determine what markup% should be.
     * They are NOT applied to customer estimates.
     *
     * @param  float  $cogs  Cost of Goods Sold (labor + cleanup + sundries + materials + taxes)
     * @return float OTD price
     */
    public function calculateWithMargins(float $cogs): float
    {
        $markupPercent = (float) ($this->markup_percent ?? 45.00);

        return round($cogs * (1 + $markupPercent / 100), 2);
    }

    /**
     * Calculate OTD (Out The Door) price from COGS using margin formula.
     *
     * PBP formula: OTD = COGS / (margin% / 100)
     * Where margin% represents the COGS percentage of the sale price.
     *
     * Example: 41% margin on $100 COGS = $100 / 0.41 = $243.90
     * COGS = $100 which is 41% of $243.90, Gross Profit = 59%
     *
     * @param  float  $cogs  Cost of Goods Sold (labor + cleanup + sundries + materials + taxes)
     * @return float OTD price
     */
    public function calculateOTD(float $cogs): float
    {
        $marginPercent = (float) ($this->margin_percent ?? 45.00);

        // Prevent division by zero
        if ($marginPercent <= 0) {
            return $cogs;
        }

        return round($cogs / ($marginPercent / 100), 2);
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
     *
     * @deprecated Use getMarginPercentForScope() instead.
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
     * Get the margin percentage for a specific scope type.
     * Interior: 50%, Exterior: 41% (based on PBP spreadsheet).
     *
     * Margin% represents the COGS percentage of the sale price.
     * 50% margin means: OTD = COGS / 0.50 (price is 2x COGS, GP = 50%)
     * 41% margin means: OTD = COGS / 0.41 (price is ~2.44x COGS, GP = 59%)
     */
    public function getMarginPercentForScope(string $scopeType): float
    {
        return match ($scopeType) {
            'interior' => (float) ($this->interior_margin_percent ?? 50.00),
            'exterior' => (float) ($this->exterior_margin_percent ?? 41.00),
            default => (float) ($this->margin_percent ?? 45.00),
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
     * Get the labor tax rate (for states that require sales tax on labor).
     * Most states don't tax labor, so default is 0%.
     */
    public function getLaborTaxRate(): float
    {
        return (float) ($this->labor_tax_rate ?? 0);
    }

    /**
     * Get the material tax rate.
     */
    public function getMaterialTaxRate(): float
    {
        return (float) ($this->material_tax_rate ?? 7.5);
    }

    /**
     * Calculate material tax from material cost.
     */
    public function calculateMaterialTax(float $materialCost): float
    {
        return round($materialCost * ($this->getMaterialTaxRate() / 100), 2);
    }

    /**
     * Calculate labor tax from labor cost (labor + cleanup).
     * Only applies in states that tax labor services.
     */
    public function calculateLaborTax(float $laborCost, float $cleanupCost): float
    {
        $totalLabor = $laborCost + $cleanupCost;

        return round($totalLabor * ($this->getLaborTaxRate() / 100), 2);
    }

    /**
     * Calculate complete COGS (Cost of Goods Sold) from components.
     *
     * COGS = Labor + Cleanup + Sundries + Materials + Material Tax + Labor Tax
     *
     * @return array{cogs: float, material_tax: float, labor_tax: float}
     */
    public function calculateCOGS(
        float $laborCost,
        float $cleanupCost,
        float $sundriesCost,
        float $materialCost
    ): array {
        $materialTax = $this->calculateMaterialTax($materialCost);
        $laborTax = $this->calculateLaborTax($laborCost, $cleanupCost);

        $cogs = $laborCost + $cleanupCost + $sundriesCost + $materialCost + $materialTax + $laborTax;

        return [
            'cogs' => round($cogs, 2),
            'material_tax' => $materialTax,
            'labor_tax' => $laborTax,
        ];
    }

    /**
     * Calculate OTD (Out The Door) price from COGS using scope-specific markup.
     *
     * @deprecated Use calculateOTDFromCOGS() instead for margin-based pricing.
     *
     * IMPORTANT: This is the ONLY margin calculation used for customer pricing.
     * The formula is: OTD = COGS × (1 + markup%)
     *
     * Interior: 50% markup (OTD = COGS × 1.50)
     * Exterior: 41% markup (OTD = COGS × 1.41)
     *
     * The other margin percentages (profit, overhead, marketing, sales, production_management)
     * are for a SEPARATE business analysis tool to help determine what markup% should be.
     * They are NOT applied to customer estimates.
     *
     * @param  float  $cogs  Cost of Goods Sold (labor + cleanup + sundries + materials + taxes)
     * @param  string  $scopeType  'interior' or 'exterior'
     * @return float OTD price
     */
    public function calculateWithMarginsForScope(float $cogs, string $scopeType): float
    {
        $markupPercent = $this->getMarkupPercentForScope($scopeType);

        return round($cogs * (1 + $markupPercent / 100), 2);
    }

    /**
     * Calculate OTD (Out The Door) price from COGS using scope-specific margin.
     *
     * PBP formula: OTD = COGS / (margin% / 100)
     * Where margin% represents the COGS percentage of the sale price.
     *
     * Interior (50% margin): OTD = COGS / 0.50 = COGS × 2
     * Exterior (41% margin): OTD = COGS / 0.41 ≈ COGS × 2.439
     *
     * Example:
     * COGS = $113.71
     * Interior 50% margin: OTD = $113.71 / 0.50 = $227.42
     * Exterior 41% margin: OTD = $113.71 / 0.41 = $277.34
     * COGS is 41% of $277.34, Gross Profit = 59%
     *
     * @param  float  $cogs  Cost of Goods Sold (labor + cleanup + sundries + materials + taxes)
     * @param  string  $scopeType  'interior' or 'exterior'
     * @return float OTD price
     */
    public function calculateOTDFromCOGS(float $cogs, string $scopeType): float
    {
        $marginPercent = $this->getMarginPercentForScope($scopeType);

        // Prevent division by zero
        if ($marginPercent <= 0) {
            return $cogs;
        }

        return round($cogs / ($marginPercent / 100), 2);
    }

    /**
     * Get the complete PBP pricing breakdown for a scope type.
     *
     * @return array{
     *     hourly_wage: float,
     *     effective_hourly_rate: float,
     *     markup_percent: float,
     *     margin_percent: float,
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
            'margin_percent' => $this->getMarginPercentForScope($scopeType),
            'cleanup_hours_ratio' => $this->getCleanupHoursRatioForScope($scopeType),
            'sundries_cost_per_hour' => $this->getSundriesCostForScope($scopeType),
            'profit_percent' => (float) ($this->net_profit_margin_percent ?? 30.00),
            'overhead_percent' => (float) ($this->overhead_percent ?? 12.00),
        ];
    }

    /**
     * Surface categories for interior projects.
     */
    public const INTERIOR_SURFACE_CATEGORIES = [
        'walls' => 'Walls',
        'ceiling' => 'Ceiling',
        'trim' => 'Trim/Baseboards',
        'doors' => 'Doors',
    ];

    /**
     * Surface categories for exterior projects.
     */
    public const EXTERIOR_SURFACE_CATEGORIES = [
        'body' => 'Body/Siding',
        'trim' => 'Trim',
        'fascia' => 'Fascia',
        'eaves' => 'Eaves/Soffit',
        'doors' => 'Doors',
    ];

    /**
     * Get surface categories for a given scope.
     *
     * @return array<string, string>
     */
    public static function getSurfaceCategoriesForScope(string $scope): array
    {
        return match ($scope) {
            'interior' => self::INTERIOR_SURFACE_CATEGORIES,
            'exterior' => self::EXTERIOR_SURFACE_CATEGORIES,
            default => self::INTERIOR_SURFACE_CATEGORIES,
        };
    }

    /**
     * Get the default paint product ID for a specific surface category and scope.
     *
     * @param  string  $category  Surface category (walls, ceiling, trim, doors, body, fascia, eaves)
     * @param  string  $scope  Project scope (interior or exterior)
     * @return int|null Paint product ID or null if not set
     */
    public function getDefaultPaintForCategory(string $category, string $scope): ?int
    {
        $defaults = $this->default_paint_products_by_category ?? [];

        return $defaults[$scope][$category] ?? null;
    }

    /**
     * Set the default paint product ID for a specific surface category and scope.
     *
     * @param  string  $category  Surface category (walls, ceiling, trim, doors, body, fascia, eaves)
     * @param  string  $scope  Project scope (interior or exterior)
     * @param  int|null  $paintProductId  Paint product ID or null to remove
     */
    public function setDefaultPaintForCategory(string $category, string $scope, ?int $paintProductId): void
    {
        $defaults = $this->default_paint_products_by_category ?? [];

        if (! isset($defaults[$scope])) {
            $defaults[$scope] = [];
        }

        if ($paintProductId === null) {
            unset($defaults[$scope][$category]);

            // Remove scope key if empty
            if (empty($defaults[$scope])) {
                unset($defaults[$scope]);
            }
        } else {
            $defaults[$scope][$category] = $paintProductId;
        }

        $this->default_paint_products_by_category = $defaults;
        $this->save();
    }

    /**
     * Get all paint defaults for a specific scope.
     *
     * @param  string  $scope  Project scope (interior or exterior)
     * @return array<string, int> Category => paint product ID mapping
     */
    public function getPaintDefaultsForScope(string $scope): array
    {
        $defaults = $this->default_paint_products_by_category ?? [];

        return $defaults[$scope] ?? [];
    }

    /**
     * Check if there are any custom paint defaults set for a scope.
     */
    public function hasPaintDefaultsForScope(string $scope): bool
    {
        return ! empty($this->getPaintDefaultsForScope($scope));
    }

    /**
     * Check if Stripe Connect is configured and enabled.
     */
    public function isStripeConnectConfigured(): bool
    {
        return $this->stripe_connect_enabled && config('stripe.secret');
    }

    /**
     * Check if Increase is configured and enabled.
     */
    public function isIncreaseConfigured(): bool
    {
        return $this->increase_enabled && ! empty($this->increase_access_token);
    }

    /**
     * Check if payment splitting is enabled and configured.
     */
    public function isSplittingEnabled(): bool
    {
        return $this->payment_split_enabled && ! empty($this->payment_split_config);
    }

    /**
     * Get the payment split configuration.
     *
     * @return array<int, array{name: string, percentage: float, stripe_account_id: string|null, increase_account_id: string|null}>
     */
    public function getPaymentSplitConfig(): array
    {
        return $this->payment_split_config ?? [];
    }
}
