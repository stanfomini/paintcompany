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
     * Calculate price using the PBP (Painting Business Pricing) formula.
     *
     * Uses CompanySetting for scope-specific hourly rates. Material costs
     * should be calculated separately using PaintProduct pricing.
     *
     * @param  array<int, float>|null  $coatMultipliers
     * @return array{labor_hours: float, labor_cost: float, coat_multiplier: float}
     */
    public function calculatePrice(
        float $quantity,
        float $productionRate,
        int $coats = 2,
        string $scopeType = 'interior',
        ?array $coatMultipliers = null
    ): array {
        $coatMultiplier = $this->getCoatMultiplier($coats, $coatMultipliers);

        $settings = CompanySetting::getInstance();
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        $laborHours = ($quantity / $productionRate) * $coatMultiplier;
        $laborCost = $laborHours * $effectiveRate;

        return [
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'coat_multiplier' => $coatMultiplier,
        ];
    }

    /**
     * Calculate price from a ProductionRate model using CompanySetting rates.
     *
     * @return array{labor_hours: float, labor_cost: float, coat_multiplier: float}
     */
    public function calculatePriceFromRate(ProductionRate $rate, float $quantity, ?int $coats = null, string $scopeType = 'interior'): array
    {
        $coats = $coats ?? $rate->default_coats ?? 2;

        return $this->calculatePrice(
            quantity: $quantity,
            productionRate: (float) $rate->production_rate,
            coats: $coats,
            scopeType: $scopeType,
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
     * Now uses CompanySetting for scope-specific rates.
     *
     * @return array{rate: ProductionRate|null, sqft: float, coats: int, scope_type: string, labor_hours: float, labor_cost: float, coat_multiplier: float, error: string|null}
     */
    public function testCalculator(float $sqft, int $rateId, int $coats = 2, string $scopeType = 'interior'): array
    {
        $rate = ProductionRate::find($rateId);

        if (! $rate) {
            return [
                'rate' => null,
                'sqft' => $sqft,
                'coats' => $coats,
                'scope_type' => $scopeType,
                'labor_hours' => 0,
                'labor_cost' => 0,
                'coat_multiplier' => 0,
                'error' => 'Production rate not found.',
            ];
        }

        $calculation = $this->calculatePriceFromRate($rate, $sqft, $coats, $scopeType);

        return [
            'rate' => $rate,
            'sqft' => $sqft,
            'coats' => $coats,
            'scope_type' => $scopeType,
            'labor_hours' => $calculation['labor_hours'],
            'labor_cost' => $calculation['labor_cost'],
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
     * Note: Material cost is no longer stored on production rates - use PaintProduct pricing.
     *
     * @return array{rate: ProductionRate|null, quantity: float, coats: int, scope_type: string, labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, base_total: float, with_margins: float, coat_multiplier: float, effective_hourly_rate: float, error: string|null}
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

        // PBP Formula Step 6: Calculate base total (labor only - materials from PaintProduct)
        $baseTotal = $laborCost + $cleanupCost + $sundriesCost;

        // PBP Formula Step 7: Apply scope-specific margin (OTD = COGS / (margin% / 100))
        $withMargins = $settings->calculateOTDFromCOGS($baseTotal, $scopeType);

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
            'base_total' => round($baseTotal, 2),
            'with_margins' => round($withMargins, 2),
            'coat_multiplier' => $coatMultiplier,
            'effective_hourly_rate' => round($effectiveHourlyRate, 2),
            'error' => null,
        ];
    }

    /**
     * Seed production rates from the PBP spreadsheets.
     *
     * Data sources:
     * - Interior: Frederick Est spreadsheet (columns F-P)
     * - Exterior: PBP Exterior 2.0 spreadsheet (columns G-BA)
     *
     * These are exact values from the spreadsheets.
     */
    public function seedDefaultRates(): void
    {
        $this->seedInteriorRates();
        $this->seedExteriorRates();
    }

    /**
     * Seed interior production rates from Frederick Est spreadsheet.
     */
    private function seedInteriorRates(): void
    {
        $category = $this->getOrCreateCategory('Interior Painting', 'interior');

        // Interior Prep Inputs (Column F-G)
        $this->seedRates($category, 'interior', 'prep', [
            ['Masking flooring', 250, 'sqft_hr'],
            ['Tape line with Caulking', 30, 'lnft_hr'],
            ['Tape line', 40, 'lnft_hr'],
            ['Prep for newly installed baseboards, puttying & caulking', 50, 'lnft_hr'],
            ['Smooth Walls prime new drywall', 150, 'sqft_hr'],
            ['Textured prime new drywall', 100, 'sqft_hr'],
            ['Hand cut line same color (Walls to Ceiling only)', 60, 'lnft_hr'],
            ['Hand cut line change color (Walls to Ceiling only)', 40, 'lnft_hr'],
            ['Caulking in baseboards', 75, 'lnft_hr'],
        ]);

        // Interior Wall Types (Column H-J)
        $this->seedRates($category, 'interior', 'walls', [
            ['Textured Walls same color', 265, 'sqft_hr'],
            ['Textured Walls change color', 150, 'sqft_hr'],
            ['Smooth Walls same color', 210, 'sqft_hr'],
            ['Smooth Walls change color', 120, 'sqft_hr'],
            ['Prime and paint new textured drywall', 95.78, 'sqft_hr'],
            ['Prime and paint new smooth drywall', 76.36, 'sqft_hr'],
        ]);

        // Interior Ceiling Types (Column K-L)
        $this->seedRates($category, 'interior', 'ceiling', [
            ['Textured Ceiling same color', 175, 'sqft_hr'],
            ['Textured Ceiling change color', 100, 'sqft_hr'],
            ['Textured Vaulted or > 18ft ceilings same color', 100, 'sqft_hr'],
            ['Textured Vaulted or > 18ft ceilings change color', 50, 'sqft_hr'],
            ['Smooth Ceiling same color', 100, 'sqft_hr'],
            ['Smooth Ceiling change color', 50, 'sqft_hr'],
            ['Smooth Vaulted or over 18ft ceilings same color', 75, 'sqft_hr'],
            ['Smooth Vaulted or over 18ft ceilings change color', 50, 'sqft_hr'],
            ['Popcorn Ceiling same color', 100, 'sqft_hr'],
            ['Popcorn Ceiling change color', 65, 'sqft_hr'],
            ['Popcorn Vaulted or over 18ft ceilings same color', 75, 'sqft_hr'],
            ['Popcorn Vaulted or over 18ft ceilings change color', 40, 'sqft_hr'],
            ['Prime and paint new drywall textured Ceilings', 63.64, 'sqft_hr'],
        ]);

        // Interior Baseboard Types (Column M-N)
        $this->seedRates($category, 'interior', 'baseboard', [
            ['Baseboards same color', 40, 'lnft_hr'],
            ['Baseboards change color', 30, 'lnft_hr'],
            ['Baseboards change stain to paint', 12, 'lnft_hr'],
            ['New Baseboards (never been painted)', 18.75, 'lnft_hr'],
            ['Prime & 2 coats Baseboards', 25, 'lnft_hr'],
            ['Prime & 2 coats Baseboards & caulk in', 21.43, 'lnft_hr'],
        ]);

        // Interior Other Trim (crown molding, chair rail, window casing, etc.)
        $this->seedRates($category, 'interior', 'trim', [
            ['Crown Molding same color', 30, 'lnft_hr'],
            ['Crown Molding change color', 20, 'lnft_hr'],
            ['Crown Molding stain to paint', 10, 'lnft_hr'],
            ['Chair Rail same color', 35, 'lnft_hr'],
            ['Chair Rail change color', 25, 'lnft_hr'],
            ['Window Casing same color', 30, 'lnft_hr'],
            ['Window Casing change color', 20, 'lnft_hr'],
            ['Window Casing stain to paint', 10, 'lnft_hr'],
            ['General Trim same color', 30, 'lnft_hr'],
            ['General Trim change color', 20, 'lnft_hr'],
        ]);

        // Interior Windows (trim, sills, frames)
        $this->seedRates($category, 'interior', 'windows', [
            ['Window Trim & Sill same color', 3, 'units_hr'],
            ['Window Trim & Sill change color', 2, 'units_hr'],
            ['Window Trim & Sill stain to paint', 1, 'units_hr'],
            ['Window Frame only same color', 4, 'units_hr'],
            ['Window Frame only change color', 3, 'units_hr'],
            ['Bay Window (3-section) same color', 1.5, 'units_hr'],
            ['Bay Window (3-section) change color', 1, 'units_hr'],
            ['Picture Window Trim same color', 4, 'units_hr'],
            ['Picture Window Trim change color', 3, 'units_hr'],
        ]);

        // Interior Door Types (Column O-P) - Note: these are hours/door
        $this->seedRates($category, 'interior', 'doors', [
            ['Doors 1 side', 1, 'hours_per_unit'],
            ['Doors both sides', 2, 'hours_per_unit'],
            ['Closet Doors 1 side', 2, 'hours_per_unit'],
            ['Closet Doors both sides', 4, 'hours_per_unit'],
            ['Commercial Steel Doors 1 side', 1.38, 'hours_per_unit'],
            ['Commercial Steel Doors both sides', 2.75, 'hours_per_unit'],
            ['Same Color Doors 1 side', 0.75, 'hours_per_unit'],
            ['Same Color Doors both sides', 1.5, 'hours_per_unit'],
            ['Same Color Closet Doors 1 side', 1, 'hours_per_unit'],
            ['Same Color Closet Doors both sides', 3, 'hours_per_unit'],
            ['Same Color Commercial Steel Doors 1 side', 1.03, 'hours_per_unit'],
            ['Same Color Commercial Steel Doors both sides', 2.06, 'hours_per_unit'],
            ['Stain to paint (full sand) Doors 1 side', 2.5, 'hours_per_unit'],
        ]);
    }

    /**
     * Seed exterior production rates from PBP Exterior 2.0 spreadsheet.
     */
    private function seedExteriorRates(): void
    {
        $category = $this->getOrCreateCategory('Exterior Painting', 'exterior');

        // Exterior Prep Work (Column G-H)
        $this->seedRates($category, 'exterior', 'prep', [
            ['Power Wash (SqFt)', 1500, 'sqft_hr'],
            ['Scrape 1 Side of Board (LnFt)', 40, 'lnft_hr'],
            ['Scrape Surface (SqFt)', 100, 'sqft_hr'],
            ['Scuff Sand (SqFt)', 100, 'sqft_hr'],
            ['Light Sand (SqFt)', 50, 'sqft_hr'],
            ['Heavy Sand (SqFt)', 25, 'sqft_hr'],
            ['Priming w/ brush (SqFt)', 150, 'sqft_hr'],
            ['Priming w/ brush (LnFt)', 60, 'lnft_hr'],
            ['Scrape, Sand, & Prime (LnFt)', 25, 'lnft_hr'],
            ['Scrape, Sand, & Prime 75-100% Peeling (SqFt)', 10, 'sqft_hr'],
            ['Scrape, Sand, & Prime 50-75% Peeling (SqFt)', 50, 'sqft_hr'],
            ['Scrape, Sand, & Prime 25-50% Peeling (SqFt)', 50, 'sqft_hr'],
            ['Scrape, Sand, & Prime 25% or less Peeling (SqFt)', 200, 'sqft_hr'],
            ['Scrape, Sand, & Prime Localized Failure (#/Hr)', 6, 'units_hr'],
            ['Caulking a 1 Story home (LnFt)', 100, 'lnft_hr'],
            ['Caulking a 2 Story home (LnFt)', 75, 'lnft_hr'],
            ['Caulking the 3rd story of a home (LnFt)', 35, 'lnft_hr'],
        ]);

        // Exterior Body Application (Column I-J)
        $this->seedRates($category, 'exterior', 'body', [
            ['Siding Spray', 200, 'sqft_hr'],
            ['Siding Spray w/light backroll', 150, 'sqft_hr'],
            ['Siding Roll', 125, 'sqft_hr'],
            ['Masonry Spray', 150, 'sqft_hr'],
            ['Masonry Spray w/light backroll', 125, 'sqft_hr'],
            ['Masonry Roll', 100, 'sqft_hr'],
            ['Siding Brush', 100, 'sqft_hr'],
            ['Masonry Brush', 75, 'sqft_hr'],
            ['Accent siding Spray', 200, 'sqft_hr'],
            ['Accent siding Spray w/light backroll', 150, 'sqft_hr'],
            ['Accent siding Roll', 125, 'sqft_hr'],
            ['Accent Masonry Spray', 150, 'sqft_hr'],
        ]);

        // Exterior Eaves (Column K-L)
        $this->seedRates($category, 'exterior', 'eaves', [
            ['Eaves Body Color', 75, 'lnft_hr'],
            ['Eaves Trim Color', 20, 'lnft_hr'],
            ['Eaves Separate Color', 15, 'lnft_hr'],
            ['3rd Story Eaves body color', 50, 'lnft_hr'],
            ['3rd Story eaves trim color', 10, 'lnft_hr'],
            ['3rd story eaves separate color', 5, 'lnft_hr'],
        ]);

        // Exterior Fascia (Column M-N)
        $this->seedRates($category, 'exterior', 'fascia', [
            ['Fascia on 1 story home', 40, 'lnft_hr'],
            ['Fascia on 2 story home', 35, 'lnft_hr'],
            ['3rd Story Fascia', 15, 'lnft_hr'],
        ]);

        // Exterior Windows (Column O-Q)
        $this->seedRates($category, 'exterior', 'windows', [
            ['Window Vinyl with no trim', 6, 'units_hr'],
            ['Windows Wood with no trim (Body Color)', 2, 'units_hr'],
            ['Windows Vinyl with trim', 2, 'units_hr'],
            ['Windows Wood that don\'t open', 2, 'units_hr'],
            ['Windows Wood that open', 0.67, 'units_hr'],
            ['Windows 3D Vinyl', 1, 'units_hr'],
            ['Windows 3D Wood that don\'t open', 0.8, 'units_hr'],
            ['Windows 3D Wood that open', 0.57, 'units_hr'],
            ['Windows 2 tone wood that don\'t open', 0.67, 'units_hr'],
            ['Windows 2 tone wood that open', 0.5, 'units_hr'],
            ['Windows 3D 2 tone wood that don\'t open', 0.57, 'units_hr'],
            ['Windows 3D 2 tone wood that open', 0.44, 'units_hr'],
        ]);

        // Exterior Other Trim (Column S-T)
        $this->seedRates($category, 'exterior', 'trim', [
            ['Other trim on 1 story home', 40, 'lnft_hr'],
            ['Other trim on 2+ story home', 35, 'lnft_hr'],
            ['Downspouts or Posts with 3-4 sides', 20, 'lnft_hr'],
            ['3D trim', 20, 'lnft_hr'],
            ['Tudor trim facing', 50, 'lnft_hr'],
            ['Tudor trim 3D', 30, 'lnft_hr'],
            ['Remove & Reinstall Downspouts', 20, 'lnft_hr'],
            ['Just remove Downspouts', 60, 'lnft_hr'],
        ]);

        // Exterior Doors (Column U-V)
        $this->seedRates($category, 'exterior', 'exterior_doors', [
            ['Door Body Color with no trim', 4, 'units_hr'],
            ['Door Body Color with Trim', 1.33, 'units_hr'],
            ['Door Trim Color with no trim', 1.33, 'units_hr'],
            ['Door Trim Color with Trim', 1, 'units_hr'],
            ['Door Accent Color with no trim', 1.33, 'units_hr'],
            ['Door Accent Color with Trim', 0.8, 'units_hr'],
            ['Door Stained to painted with no trim', 0.57, 'units_hr'],
            ['Door Stained to painted with trim', 0.36, 'units_hr'],
        ]);

        // Exterior Wood Replacement (Column AZ-BA) - cost_per_lnft
        $this->seedRates($category, 'exterior', 'wood_replacement', [
            ['1st story trim replacement', 6.84, 'cost_per_lnft'],
            ['2nd story trim replacement', 7.57, 'cost_per_lnft'],
            ['1st story regular siding replacement', 5.08, 'cost_per_lnft'],
            ['2nd story regular siding replacement', 6.10, 'cost_per_lnft'],
            ['1st story cement fiber siding (hardy board) replacement', 5.88, 'cost_per_lnft'],
            ['2nd story cement fiber siding (hardy board) replacement', 6.85, 'cost_per_lnft'],
            ['Part of or entire door frame replacement', 113.48, 'cost_per_lnft'],
            ['1st story fascia replacement', 9.15, 'cost_per_lnft'],
            ['2nd story fascia replacement', 11.85, 'cost_per_lnft'],
            ['1st story fascia w/gutter replacement', 13.57, 'cost_per_lnft'],
            ['2nd story fascia w/gutter replacement', 17.20, 'cost_per_lnft'],
            ['Railings replacement', 9.20, 'cost_per_lnft'],
            ['1st story eave/soffit replacement', 9.45, 'cost_per_lnft'],
            ['2nd story eave/soffit replacement', 12.18, 'cost_per_lnft'],
        ]);
    }

    /**
     * Get or create a production rate category.
     */
    private function getOrCreateCategory(string $name, string $type): ProductionRateCategory
    {
        return ProductionRateCategory::firstOrCreate(
            ['name' => $name],
            [
                'type' => $type,
                'description' => "{$name} production rates from PBP spreadsheet",
                'sort_order' => $type === 'interior' ? 1 : 2,
                'is_active' => true,
            ]
        );
    }

    /**
     * Seed production rates for a category.
     *
     * @param  array<int, array{0: string, 1: float, 2: string}>  $rates  [name, rate, rate_type]
     */
    private function seedRates(
        ProductionRateCategory $category,
        string $scope,
        string $surfaceCategory,
        array $rates
    ): void {
        $coatMultipliers = [
            '1' => 1.0,
            '2' => 1.8,
            '3' => 2.5,
        ];

        foreach ($rates as $index => $rateData) {
            [$name, $rate, $rateType] = $rateData;

            // Determine unit_type based on rate_type
            $unitType = match ($rateType) {
                'sqft_hr' => ProductionRate::UNIT_TYPE_SQFT,
                'lnft_hr' => ProductionRate::UNIT_TYPE_LNFT,
                'units_hr', 'hours_per_unit' => ProductionRate::UNIT_TYPE_UNIT,
                'cost_per_lnft' => ProductionRate::UNIT_TYPE_LNFT,
                default => ProductionRate::UNIT_TYPE_SQFT,
            };

            ProductionRate::updateOrCreate(
                [
                    'scope' => $scope,
                    'surface_category' => $surfaceCategory,
                    'name' => $name,
                ],
                [
                    'category_id' => $category->id,
                    'description' => 'Production rate from PBP spreadsheet',
                    'unit_type' => $unitType,
                    'production_rate' => $rate,
                    'rate_type' => $rateType,
                    'coat_multipliers' => $coatMultipliers,
                    'default_coats' => 2,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ]
            );
        }
    }
}
