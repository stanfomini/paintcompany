<?php

namespace Database\Seeders;

use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use Illuminate\Database\Seeder;

/**
 * Seeds production rates from the PBP spreadsheets.
 *
 * Data sources:
 * - Interior: Frederick Est spreadsheet (columns F-P)
 * - Exterior: PBP Exterior 2.0 spreadsheet (columns G-BA)
 *
 * These are exact values from the spreadsheets - do not modify without
 * verifying against the original Google Sheets documents.
 */
class SpreadsheetProductionRateSeeder extends Seeder
{
    /**
     * Default coat multipliers.
     *
     * @var array<string, float>
     */
    private const COAT_MULTIPLIERS = [
        '1' => 1.0,
        '2' => 1.8,
        '3' => 2.5,
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
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
            ['Masking flooring', 250, 'sqft_hr', 'Masking flooring'],
            ['Tape line with Caulking', 30, 'lnft_hr', 'Tape line with Caulking'],
            ['Tape line', 40, 'lnft_hr', 'Tape line'],
            ['Prep for newly installed baseboards, puttying & caulking', 50, 'lnft_hr', 'Prep for newly installed baseboards, puttying & caulking'],
            ['Smooth Walls prime new drywall', 150, 'sqft_hr', 'Smooth Walls prime new drywall'],
            ['Textured prime new drywall', 100, 'sqft_hr', 'Textured prime new drywall'],
            ['Hand cut line same color (Walls to Ceiling only)', 60, 'lnft_hr', 'Hand cut line same color (Walls to Ceiling only)'],
            ['Hand cut line change color (Walls to Ceiling only)', 40, 'lnft_hr', 'Hand cut line change color (Walls to Ceiling only)'],
            ['Caulking in baseboards', 75, 'lnft_hr', 'Caulking in baseboards'],
        ]);

        // Interior Wall Types (Column H-J)
        $this->seedRates($category, 'interior', 'walls', [
            ['Textured Walls same color', 265, 'sqft_hr', 'Textured Walls same color'],
            ['Textured Walls change color', 150, 'sqft_hr', 'Textured Walls change color'],
            ['Smooth Walls same color', 210, 'sqft_hr', 'Smooth Walls same color'],
            ['Smooth Walls change color', 120, 'sqft_hr', 'Smooth Walls change color'],
            ['Prime and paint new textured drywall', 95.78, 'sqft_hr', 'prime and paint new textured drywall'],
            ['Prime and paint new smooth drywall', 76.36, 'sqft_hr', 'prime and paint new smoothe drywall'],
        ]);

        // Interior Ceiling Types (Column K-L)
        $this->seedRates($category, 'interior', 'ceiling', [
            ['Textured Ceiling same color', 175, 'sqft_hr', 'Textured Ceiling same color'],
            ['Textured Ceiling change color', 100, 'sqft_hr', 'Textured Ceiling change color'],
            ['Textured Vaulted or > 18ft ceilings same color', 100, 'sqft_hr', 'Textured Vaulted or > 18ft ceilings same color'],
            ['Textured Vaulted or > 18ft ceilings change color', 50, 'sqft_hr', 'Textured Vaulted or > 18ft ceilings change color'],
            ['Smooth Ceiling same color', 100, 'sqft_hr', 'Smooth Ceiling same color'],
            ['Smooth Ceiling change color', 50, 'sqft_hr', 'Smooth Ceiling Change color'],
            ['Smooth Vaulted or over 18ft ceilings same color', 75, 'sqft_hr', 'Smooth Vaulted or over 18ft ceilings same color'],
            ['Smooth Vaulted or over 18ft ceilings change color', 50, 'sqft_hr', 'Smooth Vaulted or over 18ft ceilings change color'],
            ['Popcorn Ceiling same color', 100, 'sqft_hr', 'Popcorn Ceiling same color'],
            ['Popcorn Ceiling change color', 65, 'sqft_hr', 'Popcorn Ceiling Change color'],
            ['Popcorn Vaulted or over 18ft ceilings same color', 75, 'sqft_hr', 'Popcorn Vaulted or over 18ft ceilings same color'],
            ['Popcorn Vaulted or over 18ft ceilings change color', 40, 'sqft_hr', 'Popcorn Vaulted or over 18ft ceilings change color'],
            ['Prime and paint new drywall textured Ceilings', 63.64, 'sqft_hr', 'Prime and paint new drywall textured Ceilings'],
        ]);

        // Interior Baseboard Types (Column M-N)
        $this->seedRates($category, 'interior', 'baseboard', [
            ['Baseboards same color', 40, 'lnft_hr', 'Baseboards same color'],
            ['Baseboards change color', 30, 'lnft_hr', 'Baseboards change color'],
            ['Baseboards change stain to paint', 12, 'lnft_hr', 'Baseboards change stain to paint'],
            ['New Baseboards (never been painted)', 18.75, 'lnft_hr', 'New Baseboards (never been painted)'],
            ['Prime & 2 coats Baseboards', 25, 'lnft_hr', 'Prime & 2 coats Baseboards'],
            ['Prime & 2 coats Baseboards & caulk in', 21.43, 'lnft_hr', 'Prime & 2 coats Baseboards & caulk in'],
        ]);

        // Interior Door Types (Column O-P) - Note: these are hours/door, not doors/hr
        $this->seedRates($category, 'interior', 'doors', [
            ['Doors 1 side', 1, 'hours_per_unit', 'Doors 1 side'],
            ['Doors both sides', 2, 'hours_per_unit', 'Doors both sides'],
            ['Closet Doors 1 side', 2, 'hours_per_unit', 'Closet Doors 1 side'],
            ['Closet Doors both sides', 4, 'hours_per_unit', 'Closet Doors both sides'],
            ['Commercial Steel Doors 1 side', 1.38, 'hours_per_unit', 'Commercial Steel Doors 1 side'],
            ['Commercial Steel Doors both sides', 2.75, 'hours_per_unit', 'Commercial Steel Doors both sides'],
            ['Same Color Doors 1 side', 0.75, 'hours_per_unit', 'Same Color Doors 1 side'],
            ['Same Color Doors both sides', 1.5, 'hours_per_unit', 'Same Color Doors both sides'],
            ['Same Color Closet Doors 1 side', 1, 'hours_per_unit', 'Same Color Closet Doors 1 side'],
            ['Same Color Closet Doors both sides', 3, 'hours_per_unit', 'Same Color Closet Doors both sides'],
            ['Same Color Commercial Steel Doors 1 side', 1.03, 'hours_per_unit', 'Same Color Commercial Steel Doors 1 side'],
            ['Same Color Commercial Steel Doors both sides', 2.06, 'hours_per_unit', 'Same Color Commercial Steel Doors both sides'],
            ['Stain to paint (full sand) Doors 1 side', 2.5, 'hours_per_unit', 'Stain to paint (full sand) Doors 1 side'],
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
            ['Power Wash (SqFt)', 1500, 'sqft_hr', 'Power Wash (SqFt)'],
            ['Scrape 1 Side of Board (LnFt)', 40, 'lnft_hr', 'Scrape 1 Side of Board (LnFt)'],
            ['Scrape Surface (SqFt)', 100, 'sqft_hr', 'Scrape Surface (SqFt)'],
            ['Scuff Sand (SqFt)', 100, 'sqft_hr', 'Scuff Sand (SqFt)'],
            ['Light Sand (SqFt)', 50, 'sqft_hr', 'Light Sand (SqFt)'],
            ['Heavy Sand (SqFt)', 25, 'sqft_hr', 'Heavy Sand (SqFt)'],
            ['Priming w/ brush (SqFt)', 150, 'sqft_hr', 'Priming w/ brush (SqFt)'],
            ['Priming w/ brush (LnFt)', 60, 'lnft_hr', 'Priming w/ brush (LnFt)'],
            ['Scrape, Sand, & Prime (LnFt)', 25, 'lnft_hr', 'Scrape, Sand, & Prime (LnFt)'],
            ['Scrape, Sand, & Prime 75-100% Peeling (SqFt)', 10, 'sqft_hr', 'Scrape, Sand, & Prime 75-100% Peeling (SqFt)'],
            ['Scrape, Sand, & Prime 50-75% Peeling (SqFt)', 50, 'sqft_hr', 'Scrape, Sand, & Prime 50-75% Peeling (SqFt)'],
            ['Scrape, Sand, & Prime 25-50% Peeling (SqFt)', 50, 'sqft_hr', 'Scrape, Sand, & Prime 25-50% Peeling (SqFt)'],
            ['Scrape, Sand, & Prime 25% or less Peeling (SqFt)', 200, 'sqft_hr', 'Scrape, Sand, & Prime 25% or less Peeling (SqFt)'],
            ['Scrape, Sand, & Prime Localized Failure (#/Hr)', 6, 'units_hr', 'Scrape, Sand, & Prime Localized Failure (#/Hr)'],
            ['Caulking a 1 Story home (LnFt)', 100, 'lnft_hr', 'Caulking a 1 Story home (LnFt)'],
            ['Caulking a 2 Story home (LnFt)', 75, 'lnft_hr', 'Caulking a 2 Story home (LnFt)'],
            ['Caulking the 3rd story of a home (LnFt)', 35, 'lnft_hr', 'Caulking the 3rd story of a home (LnFt)'],
        ]);

        // Exterior Body Application (Column I-J)
        $this->seedRates($category, 'exterior', 'body', [
            ['Siding Spray', 200, 'sqft_hr', 'Siding Spray'],
            ['Siding Spray w/light backroll', 150, 'sqft_hr', 'Siding Spray w/light backroll'],
            ['Siding Roll', 125, 'sqft_hr', 'Siding Roll'],
            ['Masonry Spray', 150, 'sqft_hr', 'Masonry Spray'],
            ['Masonry Spray w/light backroll', 125, 'sqft_hr', 'Masonry Spray w/light backroll'],
            ['Masonry Roll', 100, 'sqft_hr', 'Masonry Roll'],
            ['Siding Brush', 100, 'sqft_hr', 'Siding Brush'],
            ['Masonry Brush', 75, 'sqft_hr', 'Masonry Brush'],
            ['Accent siding Spray', 200, 'sqft_hr', 'Accent siding Spray'],
            ['Accent siding Spray w/light backroll', 150, 'sqft_hr', 'Accent siding Spray w/light backroll'],
            ['Accent siding Roll', 125, 'sqft_hr', 'Accent siding Roll'],
            ['Accent Masonry Spray', 150, 'sqft_hr', 'Accent Masonry Spray'],
        ]);

        // Exterior Eaves (Column K-L)
        $this->seedRates($category, 'exterior', 'eaves', [
            ['Eaves Body Color', 75, 'lnft_hr', 'Eaves Body Color'],
            ['Eaves Trim Color', 20, 'lnft_hr', 'Eaves Trim Color'],
            ['Eaves Separate Color', 15, 'lnft_hr', 'Eaves Separate Color'],
            ['3rd Story Eaves body color', 50, 'lnft_hr', '3rd Story Eaves body color'],
            ['3rd Story eaves trim color', 10, 'lnft_hr', '3rd Story eaves trim color'],
            ['3rd story eaves separate color', 5, 'lnft_hr', '3rd story eaves separate color'],
        ]);

        // Exterior Fascia (Column M-N)
        $this->seedRates($category, 'exterior', 'fascia', [
            ['Fascia on 1 story home', 40, 'lnft_hr', 'Fascia on 1 story home'],
            ['Fascia on 2 story home', 35, 'lnft_hr', 'Fascia on 2 story home'],
            ['3rd Story Fascia', 15, 'lnft_hr', '3rd Story Fascia'],
        ]);

        // Exterior Windows (Column O-Q) - Note: some are units/hr, some are hours/unit
        $this->seedRates($category, 'exterior', 'windows', [
            ['Window Vinyl with no trim', 6, 'units_hr', 'Window Vinyl with no trim'],
            ['Windows Wood with no trim (Body Color)', 2, 'units_hr', 'Windows Wood with no trim (Body Color)'],
            ['Windows Vinyl with trim', 2, 'units_hr', 'Windows Vinyl with trim'],
            ['Windows Wood that don\'t open', 2, 'units_hr', 'Windows Wood that don\'t open'],
            ['Windows Wood that open', 0.67, 'units_hr', 'Windows Wood that open'],
            ['Windows 3D Vinyl', 1, 'units_hr', 'Windows 3D Vinyl'],
            ['Windows 3D Wood that don\'t open', 0.8, 'units_hr', 'Windows 3D Wood that don\'t open'],
            ['Windows 3D Wood that open', 0.57, 'units_hr', 'Windows 3D Wood that open'],
            ['Windows 2 tone wood that don\'t open', 0.67, 'units_hr', 'Windows 2 tone wood that don\'t open'],
            ['Windows 2 tone wood that open', 0.5, 'units_hr', 'Windows 2 tone wood that open'],
            ['Windows 3D 2 tone wood that don\'t open', 0.57, 'units_hr', 'Windows 3D 2 tone wood that don\'t open'],
            ['Windows 3D 2 tone wood that open', 0.44, 'units_hr', 'Windows 3D 2 tone wood that open'],
        ]);

        // Exterior Other Trim (Column S-T)
        $this->seedRates($category, 'exterior', 'trim', [
            ['Other trim on 1 story home', 40, 'lnft_hr', 'Other trim on 1 story home'],
            ['Other trim on 2+ story home', 35, 'lnft_hr', 'Other trim on 2+ story home'],
            ['Downspouts or Posts with 3-4 sides', 20, 'lnft_hr', 'Downspouts or Posts with 3-4 sides'],
            ['3D trim', 20, 'lnft_hr', '3D trim'],
            ['Tudor trim facing', 50, 'lnft_hr', 'Tudor trim facing'],
            ['Tudor trim 3D', 30, 'lnft_hr', 'tudor trim 3D'],
            ['Remove & Reinstall Downspouts', 20, 'lnft_hr', 'Remove & Reinstall Downspouts'],
            ['Just remove Downspouts', 60, 'lnft_hr', 'Just remove Downspouts'],
        ]);

        // Exterior Doors (Column U-V)
        $this->seedRates($category, 'exterior', 'exterior_doors', [
            ['Door Body Color with no trim', 4, 'units_hr', 'Door Body Color with no trim'],
            ['Door Body Color with Trim', 1.33, 'units_hr', 'Door Body Color with Trim'],
            ['Door Trim Color with no trim', 1.33, 'units_hr', 'Door Trim Color with no trim'],
            ['Door Trim Color with Trim', 1, 'units_hr', 'Door Trim Color with Trim'],
            ['Door Accent Color with no trim', 1.33, 'units_hr', 'Door Accent Color with no trim'],
            ['Door Accent Color with Trim', 0.8, 'units_hr', 'Door Accent Color with Trim'],
            ['Door Stained to painted with no trim', 0.57, 'units_hr', 'Door Stained to painted with no trim'],
            ['Door Stained to painted with trim', 0.36, 'units_hr', 'Door Stained to painted with trim'],
        ]);

        // Exterior Wood Replacement (Column AZ-BA) - These are COST per lnft, not production rates
        $this->seedRates($category, 'exterior', 'wood_replacement', [
            ['1st story trim replacement', 6.84, 'cost_per_lnft', '1st story trim'],
            ['2nd story trim replacement', 7.57, 'cost_per_lnft', '2nd story trim'],
            ['1st story regular siding replacement', 5.08, 'cost_per_lnft', '1st story regular siding'],
            ['2nd story regular siding replacement', 6.10, 'cost_per_lnft', '2nd story regular siding'],
            ['1st story cement fiber siding (hardy board) replacement', 5.88, 'cost_per_lnft', '1st story cement fiber siding (hardy board)'],
            ['2nd story cement fiber siding (hardy board) replacement', 6.85, 'cost_per_lnft', '2nd story cement fiber siding (hardy board)'],
            ['Part of or entire door frame replacement', 113.48, 'cost_per_lnft', 'Part of or entire door frame'],
            ['1st story fascia replacement', 9.15, 'cost_per_lnft', '1st story fascia'],
            ['2nd story fascia replacement', 11.85, 'cost_per_lnft', '2nd story fascia'],
            ['1st story fascia w/gutter replacement', 13.57, 'cost_per_lnft', '1st story fascia w/gutter'],
            ['2nd story fascia w/gutter replacement', 17.20, 'cost_per_lnft', '2nd story fascia w/gutter'],
            ['Railings replacement', 9.20, 'cost_per_lnft', 'Railings'],
            ['1st story eave/soffit replacement', 9.45, 'cost_per_lnft', '1st story eave/soffit'],
            ['2nd story eave/soffit replacement', 12.18, 'cost_per_lnft', '2nd story eave/soffit'],
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
     * @param  array<int, array{0: string, 1: float, 2: string, 3: string}>  $rates  [name, rate, rate_type, spreadsheet_name]
     */
    private function seedRates(
        ProductionRateCategory $category,
        string $scope,
        string $surfaceCategory,
        array $rates
    ): void {
        foreach ($rates as $index => $rateData) {
            [$name, $rate, $rateType, $spreadsheetName] = $rateData;

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
                    'spreadsheet_name' => $spreadsheetName,
                ],
                [
                    'category_id' => $category->id,
                    'name' => $name,
                    'description' => "Production rate from PBP spreadsheet: {$spreadsheetName}",
                    'unit_type' => $unitType,
                    'production_rate' => $rate,
                    'rate_type' => $rateType,
                    'coat_multipliers' => self::COAT_MULTIPLIERS,
                    'default_coats' => 2,
                    'is_active' => true,
                    'sort_order' => $index + 1,
                ]
            );
        }
    }
}
