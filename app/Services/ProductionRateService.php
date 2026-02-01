<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;

class ProductionRateService
{
    /**
     * Default coat multipliers used when none are provided.
     */
    private const DEFAULT_COAT_MULTIPLIERS = [
        1 => 1.0,
        2 => 1.8,
        3 => 2.5,
    ];

    /**
     * Calculate price using the production rate formula.
     *
     * Formula: ((quantity / productionRate) * hourlyLaborRate + (quantity * materialCostPerUnit)) * coatMultiplier
     *
     * @param  array<int, float>|null  $coatMultipliers
     * @return array{labor_hours: float, labor_cost: float, material_cost: float, total: float, coat_multiplier: float}
     */
    public function calculatePrice(
        float $quantity,
        float $productionRate,
        float $hourlyLaborRate,
        float $materialCostPerUnit,
        int $coats = 2,
        ?array $coatMultipliers = null
    ): array {
        $coatMultiplier = $this->getCoatMultiplier($coats, $coatMultipliers);

        $laborHours = $quantity / $productionRate;
        $laborCost = $laborHours * $hourlyLaborRate;
        $materialCost = $quantity * $materialCostPerUnit;

        $total = ($laborCost + $materialCost) * $coatMultiplier;

        return [
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'material_cost' => round($materialCost, 2),
            'total' => round($total, 2),
            'coat_multiplier' => $coatMultiplier,
        ];
    }

    /**
     * Calculate price from a ProductionRate model.
     *
     * @return array{labor_hours: float, labor_cost: float, material_cost: float, total: float, coat_multiplier: float}
     */
    public function calculatePriceFromRate(ProductionRate $rate, float $quantity, ?int $coats = null): array
    {
        $coats = $coats ?? $rate->default_coats ?? 2;

        return $this->calculatePrice(
            quantity: $quantity,
            productionRate: (float) $rate->production_rate,
            hourlyLaborRate: (float) $rate->hourly_labor_rate,
            materialCostPerUnit: (float) $rate->material_cost_per_unit,
            coats: $coats,
            coatMultipliers: $rate->coat_multipliers
        );
    }

    /**
     * Get the coat multiplier for a given number of coats.
     *
     * @param  array<int, float>|null  $coatMultipliers
     */
    public function getCoatMultiplier(int $coats, ?array $coatMultipliers = null): float
    {
        $multipliers = $coatMultipliers ?? self::DEFAULT_COAT_MULTIPLIERS;

        // Check if we have an exact match
        if (isset($multipliers[$coats])) {
            return (float) $multipliers[$coats];
        }

        // For 3+ coats, use the multiplier for 3 coats if available
        if ($coats >= 3 && isset($multipliers[3])) {
            return (float) $multipliers[3];
        }

        // Fallback to default multipliers
        if ($coats === 1) {
            return 1.0;
        }

        if ($coats === 2) {
            return 1.8;
        }

        // 3+ coats
        return 2.5;
    }

    /**
     * Calculate wall square footage for a room.
     *
     * Formula: 2 * (length + width) * height
     */
    public function calculateWallSqft(float $length, float $width, float $height): float
    {
        return 2 * ($length + $width) * $height;
    }

    /**
     * Calculate ceiling square footage for a room.
     *
     * Formula: length * width
     */
    public function calculateCeilingSqft(float $length, float $width): float
    {
        return $length * $width;
    }

    /**
     * Calculate the total surface area for an estimate area.
     *
     * Sums all included surface totals for an area.
     *
     * @param  object  $area  EstimateArea model (when implemented)
     */
    public function calculateAreaSurfaces(object $area): float
    {
        $total = 0.0;

        // Check for common surface properties that might exist on EstimateArea
        if (isset($area->wall_sqft) && ($area->include_walls ?? true)) {
            $total += (float) $area->wall_sqft;
        }

        if (isset($area->ceiling_sqft) && ($area->include_ceiling ?? false)) {
            $total += (float) $area->ceiling_sqft;
        }

        if (isset($area->trim_lnft) && ($area->include_trim ?? false)) {
            $total += (float) $area->trim_lnft;
        }

        // If the area has a surfaces relationship, sum those
        if (method_exists($area, 'surfaces') && $area->surfaces) {
            foreach ($area->surfaces as $surface) {
                if ($surface->is_included ?? true) {
                    $total += (float) ($surface->total ?? 0);
                }
            }
        }

        return $total;
    }

    /**
     * Calculate the total of all area surfaces for an estimate.
     *
     * Sums all area surface totals for an estimate.
     */
    public function calculateEstimateAreasTotal(Estimate $estimate): float
    {
        $total = 0.0;

        // If the estimate has an areas relationship, sum those
        if (method_exists($estimate, 'areas') && $estimate->areas) {
            foreach ($estimate->areas as $area) {
                $total += $this->calculateAreaSurfaces($area);
            }
        }

        // Fallback: calculate from items if no areas relationship
        if ($total === 0.0 && $estimate->items) {
            foreach ($estimate->items as $item) {
                $total += (float) ($item->quantity ?? 0);
            }
        }

        return $total;
    }

    /**
     * Test calculator for the settings UI.
     *
     * Returns a detailed breakdown for testing production rate calculations.
     *
     * @return array{rate: ProductionRate|null, sqft: float, coats: int, labor_hours: float, labor_cost: float, material_cost: float, total: float, coat_multiplier: float, error: string|null}
     */
    public function testCalculator(float $sqft, int $rateId, int $coats = 2): array
    {
        $rate = ProductionRate::find($rateId);

        if (! $rate) {
            return [
                'rate' => null,
                'sqft' => $sqft,
                'coats' => $coats,
                'labor_hours' => 0,
                'labor_cost' => 0,
                'material_cost' => 0,
                'total' => 0,
                'coat_multiplier' => 0,
                'error' => 'Production rate not found.',
            ];
        }

        $calculation = $this->calculatePriceFromRate($rate, $sqft, $coats);

        return [
            'rate' => $rate,
            'sqft' => $sqft,
            'coats' => $coats,
            'labor_hours' => $calculation['labor_hours'],
            'labor_cost' => $calculation['labor_cost'],
            'material_cost' => $calculation['material_cost'],
            'total' => $calculation['total'],
            'coat_multiplier' => $calculation['coat_multiplier'],
            'error' => null,
        ];
    }

    /**
     * Test calculator using PBP (Painting Business Pricing) formula.
     *
     * Uses scope-specific rates from CompanySetting:
     * - Interior: $21/hr wage × 1.35 burden, 50% markup, 16:1 cleanup, $3.30 sundries
     * - Exterior: $27/hr wage × 1.35 burden, 41% markup, 20:1 cleanup, $1.00 sundries
     *
     * @return array{rate: ProductionRate|null, quantity: float, coats: int, scope_type: string, labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float, coat_multiplier: float, effective_hourly_rate: float, error: string|null}
     */
    public function testCalculatorPBP(float $quantity, int $rateId, int $coats = 2, string $scopeType = 'interior'): array
    {
        $rate = ProductionRate::find($rateId);

        if (! $rate) {
            return [
                'rate' => null,
                'quantity' => $quantity,
                'coats' => $coats,
                'scope_type' => $scopeType,
                'labor_hours' => 0,
                'labor_cost' => 0,
                'cleanup_hours' => 0,
                'cleanup_cost' => 0,
                'sundries_cost' => 0,
                'material_cost' => 0,
                'base_total' => 0,
                'with_margins' => 0,
                'coat_multiplier' => 0,
                'effective_hourly_rate' => 0,
                'error' => 'Production rate not found.',
            ];
        }

        $settings = CompanySetting::getInstance();
        $coatMultiplier = $rate->getCoatMultiplier($coats);

        // PBP Formula Step 1: Calculate labor hours
        $laborHours = ($quantity / $rate->production_rate) * $coatMultiplier;

        // PBP Formula Step 2: Get scope-specific effective hourly rate
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        // PBP Formula Step 3: Calculate labor cost
        $laborCost = $laborHours * $effectiveHourlyRate;

        // PBP Formula Step 4: Calculate cleanup hours and cost
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $effectiveHourlyRate;

        // PBP Formula Step 5: Calculate sundries cost
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        // PBP Formula Step 6: Calculate material cost
        $materialCost = $quantity * $rate->material_cost_per_unit * $coatMultiplier;

        // PBP Formula Step 7: Calculate base total
        $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;

        // PBP Formula Step 8: Apply scope-specific margins
        $withMargins = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

        return [
            'rate' => $rate,
            'quantity' => $quantity,
            'coats' => $coats,
            'scope_type' => $scopeType,
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($materialCost, 2),
            'base_total' => round($baseTotal, 2),
            'with_margins' => round($withMargins, 2),
            'coat_multiplier' => $coatMultiplier,
            'effective_hourly_rate' => round($effectiveHourlyRate, 2),
            'error' => null,
        ];
    }

    /**
     * Seed default painting industry production rates.
     *
     * Creates standard production rates for interior, exterior, and specialty work.
     */
    public function seedDefaultRates(): void
    {
        $defaultCoatMultipliers = [
            '1' => 1.0,
            '2' => 1.8,
            '3' => 2.5,
        ];

        // Create Interior category
        $interiorCategory = ProductionRateCategory::firstOrCreate(
            ['name' => 'Interior Painting'],
            [
                'type' => ProductionRateCategory::TYPE_INTERIOR,
                'description' => 'Interior painting services',
                'sort_order' => 1,
                'is_active' => true,
            ]
        );

        // Create Exterior category
        $exteriorCategory = ProductionRateCategory::firstOrCreate(
            ['name' => 'Exterior Painting'],
            [
                'type' => ProductionRateCategory::TYPE_EXTERIOR,
                'description' => 'Exterior painting services',
                'sort_order' => 2,
                'is_active' => true,
            ]
        );

        // Create Specialty category
        $specialtyCategory = ProductionRateCategory::firstOrCreate(
            ['name' => 'Specialty Services'],
            [
                'type' => ProductionRateCategory::TYPE_SPECIALTY,
                'description' => 'Specialty painting and finishing services',
                'sort_order' => 3,
                'is_active' => true,
            ]
        );

        // Interior rates
        $this->createRate($interiorCategory, [
            'name' => 'Wall Painting',
            'description' => 'Standard interior wall painting',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 300.00,
            'hourly_labor_rate' => 45.00,
            'material_cost_per_unit' => 0.15,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 1,
        ]);

        $this->createRate($interiorCategory, [
            'name' => 'Ceiling Painting',
            'description' => 'Standard interior ceiling painting',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 400.00,
            'hourly_labor_rate' => 45.00,
            'material_cost_per_unit' => 0.12,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 2,
        ]);

        $this->createRate($interiorCategory, [
            'name' => 'Trim Painting',
            'description' => 'Interior trim, baseboards, and molding',
            'unit_type' => ProductionRate::UNIT_TYPE_LNFT,
            'production_rate' => 100.00,
            'hourly_labor_rate' => 50.00,
            'material_cost_per_unit' => 0.25,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 3,
        ]);

        $this->createRate($interiorCategory, [
            'name' => 'Door Painting',
            'description' => 'Interior door painting (per door)',
            'unit_type' => ProductionRate::UNIT_TYPE_UNIT,
            'production_rate' => 4.00,
            'hourly_labor_rate' => 50.00,
            'material_cost_per_unit' => 8.00,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 4,
        ]);

        $this->createRate($interiorCategory, [
            'name' => 'Cabinet Painting',
            'description' => 'Kitchen and bathroom cabinet painting',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 50.00,
            'hourly_labor_rate' => 55.00,
            'material_cost_per_unit' => 0.50,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 5,
        ]);

        // Exterior rates
        $this->createRate($exteriorCategory, [
            'name' => 'Siding Painting',
            'description' => 'Exterior siding painting',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 250.00,
            'hourly_labor_rate' => 50.00,
            'material_cost_per_unit' => 0.20,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 1,
        ]);

        $this->createRate($exteriorCategory, [
            'name' => 'Exterior Trim',
            'description' => 'Exterior trim and fascia painting',
            'unit_type' => ProductionRate::UNIT_TYPE_LNFT,
            'production_rate' => 80.00,
            'hourly_labor_rate' => 55.00,
            'material_cost_per_unit' => 0.30,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 2,
        ]);

        $this->createRate($exteriorCategory, [
            'name' => 'Deck Staining',
            'description' => 'Deck staining and sealing',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 200.00,
            'hourly_labor_rate' => 45.00,
            'material_cost_per_unit' => 0.35,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 3,
        ]);

        // Specialty rates
        $this->createRate($specialtyCategory, [
            'name' => 'Faux Finishing',
            'description' => 'Decorative faux finishing techniques',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 75.00,
            'hourly_labor_rate' => 65.00,
            'material_cost_per_unit' => 0.75,
            'coat_multipliers' => $defaultCoatMultipliers,
            'default_coats' => 2,
            'sort_order' => 1,
        ]);

        $this->createRate($specialtyCategory, [
            'name' => 'Wallpaper Removal',
            'description' => 'Wallpaper removal and wall preparation',
            'unit_type' => ProductionRate::UNIT_TYPE_SQFT,
            'production_rate' => 100.00,
            'hourly_labor_rate' => 40.00,
            'material_cost_per_unit' => 0.10,
            'coat_multipliers' => ['1' => 1.0],
            'default_coats' => 1,
            'sort_order' => 2,
        ]);
    }

    /**
     * Create a production rate if it doesn't exist.
     *
     * @param  array<string, mixed>  $data
     */
    private function createRate(ProductionRateCategory $category, array $data): ProductionRate
    {
        return ProductionRate::firstOrCreate(
            [
                'category_id' => $category->id,
                'name' => $data['name'],
            ],
            array_merge($data, [
                'category_id' => $category->id,
                'is_active' => true,
            ])
        );
    }
}
