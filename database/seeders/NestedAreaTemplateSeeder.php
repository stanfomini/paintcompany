<?php

namespace Database\Seeders;

use App\Models\NestedAreaTemplate;
use Illuminate\Database\Seeder;

class NestedAreaTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Nested area templates from PBP spreadsheets.
     * These are fixed-price items that can be added to parent areas.
     *
     * NOTE: estimated_hours and material_cost are for BACKEND REPORTING ONLY.
     * They are NOT included in customer-facing estimate totals.
     * Used for: Painter pay breakdown, company analytics, job costing.
     */
    public function run(): void
    {
        $templates = [
            // Closets (from PBP Interior Items)
            [
                'name' => 'Small Closet',
                'code' => 'CLOSET-SM',
                'description' => 'Small reach-in closet (up to 24 sq ft floor area)',
                'category' => NestedAreaTemplate::CATEGORY_CLOSET,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 95.00,
                'estimated_hours' => 1.5,    // ~60 sqft walls @ 150 sqft/hr with prep
                'material_cost' => 12.00,    // ~0.5 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 4.0,
                'default_width' => 2.5,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'Medium Closet',
                'code' => 'CLOSET-MD',
                'description' => 'Walk-in closet (25-50 sq ft floor area)',
                'category' => NestedAreaTemplate::CATEGORY_CLOSET,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 165.00,
                'estimated_hours' => 2.5,    // ~150 sqft walls @ 150 sqft/hr with prep
                'material_cost' => 22.00,    // ~1 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 6.0,
                'default_width' => 5.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Large Closet',
                'code' => 'CLOSET-LG',
                'description' => 'Master walk-in closet (50+ sq ft floor area)',
                'category' => NestedAreaTemplate::CATEGORY_CLOSET,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 245.00,
                'estimated_hours' => 3.75,   // ~225 sqft walls @ 150 sqft/hr with prep
                'material_cost' => 32.00,    // ~1.5 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 8.0,
                'default_width' => 6.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 3,
            ],
            [
                'name' => 'Closet w/ Shelving',
                'code' => 'CLOSET-SHELF',
                'description' => 'Closet with built-in shelving system (cut in around shelves)',
                'category' => NestedAreaTemplate::CATEGORY_CLOSET,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 195.00,
                'estimated_hours' => 3.5,    // Extra time for cutting around shelves
                'material_cost' => 22.00,    // ~1 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 5.0,
                'default_width' => 4.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 4,
            ],

            // Bathrooms
            [
                'name' => 'Half Bath',
                'code' => 'BATH-HALF',
                'description' => 'Half bathroom / Powder room',
                'category' => NestedAreaTemplate::CATEGORY_BATHROOM,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 175.00,
                'estimated_hours' => 2.75,   // Extra time for cut-in around fixtures
                'material_cost' => 25.00,    // ~1 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 5.0,
                'default_width' => 4.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 10,
            ],
            [
                'name' => 'Full Bath - Standard',
                'code' => 'BATH-FULL',
                'description' => 'Standard full bathroom with tub/shower',
                'category' => NestedAreaTemplate::CATEGORY_BATHROOM,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 285.00,
                'estimated_hours' => 4.25,   // Extra time for cut-in around fixtures
                'material_cost' => 35.00,    // ~1.5 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 8.0,
                'default_width' => 5.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 11,
            ],
            [
                'name' => 'Full Bath - Large',
                'code' => 'BATH-FULL-LG',
                'description' => 'Large full bathroom / Master bath',
                'category' => NestedAreaTemplate::CATEGORY_BATHROOM,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 385.00,
                'estimated_hours' => 5.75,   // Large area + fixtures
                'material_cost' => 48.00,    // ~2 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 10.0,
                'default_width' => 8.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 12,
            ],

            // Utility Rooms
            [
                'name' => 'Laundry Room - Small',
                'code' => 'UTIL-LAUND-SM',
                'description' => 'Small laundry room or laundry closet',
                'category' => NestedAreaTemplate::CATEGORY_UTILITY,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 145.00,
                'estimated_hours' => 2.25,   // Moderate complexity
                'material_cost' => 20.00,    // ~0.75 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 6.0,
                'default_width' => 4.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 20,
            ],
            [
                'name' => 'Laundry Room - Standard',
                'code' => 'UTIL-LAUND',
                'description' => 'Standard laundry room',
                'category' => NestedAreaTemplate::CATEGORY_UTILITY,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 225.00,
                'estimated_hours' => 3.5,    // Standard utility room
                'material_cost' => 28.00,    // ~1.25 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 8.0,
                'default_width' => 6.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 21,
            ],
            [
                'name' => 'Pantry',
                'code' => 'UTIL-PANT',
                'description' => 'Kitchen pantry or butler\'s pantry',
                'category' => NestedAreaTemplate::CATEGORY_UTILITY,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 125.00,
                'estimated_hours' => 2.0,    // Shelving adds time
                'material_cost' => 15.00,    // ~0.5 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 5.0,
                'default_width' => 4.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 22,
            ],
            [
                'name' => 'Mudroom - Small',
                'code' => 'UTIL-MUD-SM',
                'description' => 'Small mudroom or entry area',
                'category' => NestedAreaTemplate::CATEGORY_UTILITY,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 165.00,
                'estimated_hours' => 2.5,    // Standard room
                'material_cost' => 22.00,    // ~1 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 6.0,
                'default_width' => 5.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 23,
            ],
            [
                'name' => 'Mudroom - Large',
                'code' => 'UTIL-MUD-LG',
                'description' => 'Large mudroom with built-ins',
                'category' => NestedAreaTemplate::CATEGORY_UTILITY,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 275.00,
                'estimated_hours' => 4.25,   // Built-ins add time
                'material_cost' => 35.00,    // ~1.5 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 10.0,
                'default_width' => 6.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 24,
            ],

            // Other
            [
                'name' => 'Stairwell',
                'code' => 'OTHER-STAIR',
                'description' => 'Stairwell area (standard 2-story)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 325.00,
                'estimated_hours' => 5.5,    // Height adds difficulty
                'material_cost' => 38.00,    // ~1.5 gal paint + supplies
                'include_in_package_pricing' => false,
                'default_length' => 10.0,
                'default_width' => 4.0,
                'default_height' => 16.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 30,
            ],
            [
                'name' => 'Entry Foyer - Small',
                'code' => 'OTHER-FOYER-SM',
                'description' => 'Small entry foyer',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 145.00,
                'estimated_hours' => 2.25,   // Standard room
                'material_cost' => 20.00,    // ~0.75 gal paint + supplies
                'include_in_package_pricing' => true,
                'default_length' => 6.0,
                'default_width' => 5.0,
                'default_height' => 8.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 31,
            ],
            [
                'name' => 'Entry Foyer - 2-Story',
                'code' => 'OTHER-FOYER-2ST',
                'description' => 'Two-story entry foyer',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_INTERIOR,
                'fixed_price' => 475.00,
                'estimated_hours' => 8.0,    // Height adds significant time
                'material_cost' => 55.00,    // ~2+ gal paint + supplies
                'include_in_package_pricing' => false,
                'default_length' => 10.0,
                'default_width' => 8.0,
                'default_height' => 18.0,
                'includes_walls' => true,
                'includes_ceiling' => true,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 32,
            ],

            // ==========================================
            // EXTERIOR NESTED TEMPLATES
            // ==========================================

            // Garage Doors
            [
                'name' => 'Garage Door - Single',
                'code' => 'EXT-GARAGE-1',
                'description' => 'Single car garage door (standard 9x7)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 125.00,
                'estimated_hours' => 2.0,    // ~63 sqft @ 100 sqft/hr with prep
                'material_cost' => 18.00,    // Exterior paint + primer
                'include_in_package_pricing' => true,
                'default_length' => 9.0,
                'default_width' => 1.0,
                'default_height' => 7.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => true,
                'includes_door' => true,
                'sort_order' => 100,
            ],
            [
                'name' => 'Garage Door - Double',
                'code' => 'EXT-GARAGE-2',
                'description' => 'Double car garage door (standard 16x7)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 185.00,
                'estimated_hours' => 3.0,    // ~112 sqft @ 100 sqft/hr with prep
                'material_cost' => 28.00,    // Exterior paint + primer
                'include_in_package_pricing' => true,
                'default_length' => 16.0,
                'default_width' => 1.0,
                'default_height' => 7.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => true,
                'includes_door' => true,
                'sort_order' => 101,
            ],

            // Entry Doors (Exterior)
            [
                'name' => 'Entry Door - Standard',
                'code' => 'EXT-ENTRY-STD',
                'description' => 'Front/entry door with trim (accent color)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 95.00,
                'estimated_hours' => 1.25,   // Based on Door Accent Color with Trim rate
                'material_cost' => 15.00,    // Exterior paint
                'include_in_package_pricing' => true,
                'default_length' => 3.0,
                'default_width' => 1.0,
                'default_height' => 7.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => true,
                'includes_door' => true,
                'sort_order' => 110,
            ],
            [
                'name' => 'Entry Door - Double',
                'code' => 'EXT-ENTRY-DBL',
                'description' => 'Double entry doors with trim (accent color)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 165.00,
                'estimated_hours' => 2.5,    // 2x single door
                'material_cost' => 25.00,    // Exterior paint
                'include_in_package_pricing' => true,
                'default_length' => 6.0,
                'default_width' => 1.0,
                'default_height' => 7.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => true,
                'includes_door' => true,
                'sort_order' => 111,
            ],

            // Porch/Deck Ceilings
            [
                'name' => 'Porch Ceiling - Small',
                'code' => 'EXT-PORCH-SM',
                'description' => 'Small porch ceiling (up to 50 sqft)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 85.00,
                'estimated_hours' => 1.25,   // ~48 sqft overhead work
                'material_cost' => 12.00,    // Ceiling paint
                'include_in_package_pricing' => true,
                'default_length' => 8.0,
                'default_width' => 6.0,
                'default_height' => 1.0,
                'includes_walls' => false,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 120,
            ],
            [
                'name' => 'Porch Ceiling - Medium',
                'code' => 'EXT-PORCH-MD',
                'description' => 'Medium porch ceiling (50-100 sqft)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 145.00,
                'estimated_hours' => 2.25,   // ~96 sqft overhead work
                'material_cost' => 20.00,    // Ceiling paint
                'include_in_package_pricing' => true,
                'default_length' => 12.0,
                'default_width' => 8.0,
                'default_height' => 1.0,
                'includes_walls' => false,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 121,
            ],
            [
                'name' => 'Porch Ceiling - Large',
                'code' => 'EXT-PORCH-LG',
                'description' => 'Large porch/wrap-around ceiling (100+ sqft)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 225.00,
                'estimated_hours' => 3.5,    // ~160 sqft overhead work
                'material_cost' => 32.00,    // Ceiling paint
                'include_in_package_pricing' => true,
                'default_length' => 20.0,
                'default_width' => 8.0,
                'default_height' => 1.0,
                'includes_walls' => false,
                'includes_ceiling' => true,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 122,
            ],

            // Shutters
            [
                'name' => 'Shutters - Pair',
                'code' => 'EXT-SHUTTER-PR',
                'description' => 'One pair of shutters (2 panels)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 45.00,
                'estimated_hours' => 0.75,   // Quick item but detailed work
                'material_cost' => 8.00,     // Small amount of paint
                'include_in_package_pricing' => true,
                'default_length' => 1.0,
                'default_width' => 1.5,
                'default_height' => 4.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 130,
            ],

            // Columns/Posts
            [
                'name' => 'Column - Standard',
                'code' => 'EXT-COLUMN-STD',
                'description' => 'Standard porch column (up to 10 ft)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 65.00,
                'estimated_hours' => 1.0,    // Based on downspouts/posts rate
                'material_cost' => 10.00,    // Paint + primer
                'include_in_package_pricing' => true,
                'default_length' => 1.0,
                'default_width' => 1.0,
                'default_height' => 8.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 140,
            ],
            [
                'name' => 'Column - Tall/Decorative',
                'code' => 'EXT-COLUMN-LG',
                'description' => 'Tall or decorative column (10+ ft or ornate)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 95.00,
                'estimated_hours' => 1.5,    // Height + detail adds time
                'material_cost' => 15.00,    // Paint + primer
                'include_in_package_pricing' => true,
                'default_length' => 1.5,
                'default_width' => 1.5,
                'default_height' => 12.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 141,
            ],

            // Railings
            [
                'name' => 'Railing Section',
                'code' => 'EXT-RAIL-SEC',
                'description' => 'Railing section (per 8 linear feet)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 55.00,
                'estimated_hours' => 0.75,   // Based on railings wood replacement
                'material_cost' => 10.00,    // Paint
                'include_in_package_pricing' => true,
                'default_length' => 8.0,
                'default_width' => 0.5,
                'default_height' => 3.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 150,
            ],
            [
                'name' => 'Deck Railing - Full',
                'code' => 'EXT-RAIL-DECK',
                'description' => 'Full deck railing system (includes posts & balusters)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 185.00,
                'estimated_hours' => 3.0,    // Detailed spindle work
                'material_cost' => 28.00,    // More paint for balusters
                'include_in_package_pricing' => true,
                'default_length' => 24.0,
                'default_width' => 0.5,
                'default_height' => 3.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => false,
                'includes_door' => false,
                'sort_order' => 151,
            ],

            // Special Windows
            [
                'name' => 'Bay Window',
                'code' => 'EXT-WIN-BAY',
                'description' => 'Bay window (3-section, includes trim)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 125.00,
                'estimated_hours' => 2.0,    // Complex 3D trim
                'material_cost' => 18.00,    // Paint for trim
                'include_in_package_pricing' => true,
                'default_length' => 6.0,
                'default_width' => 2.0,
                'default_height' => 4.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 160,
            ],
            [
                'name' => 'Garden Window',
                'code' => 'EXT-WIN-GARDEN',
                'description' => 'Garden window (box-style, includes trim)',
                'category' => NestedAreaTemplate::CATEGORY_OTHER,
                'scope_type' => NestedAreaTemplate::SCOPE_EXTERIOR,
                'fixed_price' => 85.00,
                'estimated_hours' => 1.25,   // Detailed box work
                'material_cost' => 12.00,    // Paint for trim
                'include_in_package_pricing' => true,
                'default_length' => 3.0,
                'default_width' => 1.5,
                'default_height' => 3.0,
                'includes_walls' => false,
                'includes_ceiling' => false,
                'includes_trim' => true,
                'includes_door' => false,
                'sort_order' => 161,
            ],
        ];

        foreach ($templates as $template) {
            NestedAreaTemplate::updateOrCreate(
                ['code' => $template['code']],
                array_merge($template, ['is_active' => true])
            );
        }
    }
}
