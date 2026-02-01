<?php

namespace Database\Seeders;

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use Illuminate\Database\Seeder;

class EstimateTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates estimate templates with full relationships:
     * - substrate_id: linked to appropriate substrate for scope
     * - default_paint_product_id: linked to appropriate paint product for scope
     * - production_rate_id: linked to appropriate production rate
     *
     * This enables the 5-minute estimate workflow where templates auto-populate
     * all PBP pricing data.
     */
    public function run(): void
    {
        // Get or create categories
        $roomsCategory = $this->getOrCreateCategory('Rooms', EstimateTemplateCategory::TYPE_ROOM, 'Room templates with standard dimensions', 1);
        $surfacesCategory = $this->getOrCreateCategory('Surfaces', EstimateTemplateCategory::TYPE_SURFACE, 'Surface templates for trim and details', 2);
        $itemsCategory = $this->getOrCreateCategory('Items', EstimateTemplateCategory::TYPE_ITEM, 'Item templates for specialty work', 3);
        $addonsCategory = $this->getOrCreateCategory('Add-ons', EstimateTemplateCategory::TYPE_ADDON, 'Optional add-on templates', 4);

        // Get production rates
        $wallPaintingRate = ProductionRate::where('name', 'Wall Painting')->first();
        $ceilingPaintingRate = ProductionRate::where('name', 'Ceiling Painting')->first();
        $trimPaintingRate = ProductionRate::where('name', 'Trim Painting')->first();
        $cabinetPaintingRate = ProductionRate::where('name', 'Cabinet Painting')->first();
        $sidingPaintingRate = ProductionRate::where('name', 'Siding Painting')->first();
        $exteriorTrimRate = ProductionRate::where('name', 'Exterior Trim')->first();
        $deckStainingRate = ProductionRate::where('name', 'Deck Staining')->first();

        // Get substrates
        $interiorGoodSubstrate = Substrate::where('code', 'PREV-GOOD')->first();
        $exteriorGoodSubstrate = Substrate::where('code', 'EXT-PREV-GOOD')->first();
        $woodTrimSubstrate = Substrate::where('code', 'WOOD-PAINT')->first();
        $cabinetSubstrate = Substrate::where('code', 'INT-CAB-PAINT')->first();
        $woodSidingSubstrate = Substrate::where('code', 'WOOD-SID')->first();
        $deckSubstrate = Substrate::where('code', 'EXT-DECK-STAIN')->first();

        // Get paint products
        $interiorWallPaint = PaintProduct::where('code', 'SW-DUR-HOME')->first();
        $interiorCeilingPaint = PaintProduct::where('code', 'SW-PM-CEIL')->first();
        $interiorTrimPaint = PaintProduct::where('code', 'SW-PC-WB')->first();
        $exteriorPaint = PaintProduct::where('code', 'SW-DUR-EXT')->first();

        // Create interior room templates
        $this->createInteriorRoomTemplates(
            $roomsCategory,
            $wallPaintingRate,
            $interiorGoodSubstrate,
            $interiorWallPaint
        );

        // Create exterior room templates
        $this->createExteriorRoomTemplates(
            $roomsCategory,
            $sidingPaintingRate,
            $exteriorGoodSubstrate,
            $exteriorPaint
        );

        // Create interior surface templates
        $this->createInteriorSurfaceTemplates(
            $surfacesCategory,
            $trimPaintingRate,
            $woodTrimSubstrate,
            $interiorTrimPaint
        );

        // Create exterior surface templates
        $this->createExteriorSurfaceTemplates(
            $surfacesCategory,
            $exteriorTrimRate,
            $woodSidingSubstrate,
            $exteriorPaint
        );

        // Create interior item templates
        $this->createInteriorItemTemplates(
            $itemsCategory,
            $cabinetPaintingRate,
            $cabinetSubstrate,
            $interiorTrimPaint
        );

        // Create exterior item templates
        $this->createExteriorItemTemplates(
            $itemsCategory,
            $deckStainingRate,
            $deckSubstrate,
            $exteriorPaint
        );

        // Create add-on templates
        $this->createAddonTemplates($addonsCategory);
    }

    /**
     * Get or create a template category.
     */
    private function getOrCreateCategory(string $name, string $type, string $description, int $sortOrder): EstimateTemplateCategory
    {
        return EstimateTemplateCategory::firstOrCreate(
            ['name' => $name],
            [
                'type' => $type,
                'description' => $description,
                'is_active' => true,
                'sort_order' => $sortOrder,
            ]
        );
    }

    /**
     * Create interior room templates with full PBP relationships.
     *
     * Each room includes:
     * - Size options (small/medium/large) for quick selection
     * - Surface ratios for auto-calculating walls, ceiling, baseboards, doors
     * - Production rate links for PBP pricing
     */
    private function createInteriorRoomTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $wallPaintingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        // Get additional production rates for surfaces
        $ceilingRate = ProductionRate::where('name', 'Ceiling Painting')->first();
        $trimRate = ProductionRate::where('name', 'Trim Painting')->first();
        $doorRate = ProductionRate::where('name', 'Door Painting')->first();

        $rooms = [
            [
                'name' => 'Living Room',
                'icon' => 'tv',
                'description' => 'Living room with walls, ceiling, baseboards, and door',
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                    'medium' => ['sqft' => 200, 'label' => 'Medium (200 sqft)'],
                    'large' => ['sqft' => 300, 'label' => 'Large (300 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $doorRate?->id],
                ],
            ],
            [
                'name' => 'Bedroom',
                'icon' => 'home',
                'description' => 'Standard bedroom with walls, ceiling, baseboards, door, and closet',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 200, 'label' => 'Large (200 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $doorRate?->id],
                    'closet' => ['type' => 'fixed', 'price' => 75, 'label' => 'Standard Closet'],
                ],
            ],
            [
                'name' => 'Master Bedroom',
                'icon' => 'home-modern',
                'description' => 'Large master bedroom with walls, ceiling, baseboards, doors, and walk-in closet',
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                    'medium' => ['sqft' => 200, 'label' => 'Medium (200 sqft)'],
                    'large' => ['sqft' => 300, 'label' => 'Large (300 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 2, 'rate_id' => $doorRate?->id],
                    'closet' => ['type' => 'fixed', 'price' => 150, 'label' => 'Walk-in Closet'],
                ],
            ],
            [
                'name' => 'Bathroom',
                'icon' => 'sparkles',
                'description' => 'Standard bathroom with walls, ceiling, baseboards, and door',
                'sizes' => [
                    'small' => ['sqft' => 50, 'label' => 'Small (50 sqft)'],
                    'medium' => ['sqft' => 80, 'label' => 'Standard (80 sqft)'],
                    'large' => ['sqft' => 120, 'label' => 'Large (120 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $doorRate?->id],
                ],
            ],
            [
                'name' => 'Kitchen',
                'icon' => 'fire',
                'description' => 'Kitchen with walls and ceiling (cabinets priced separately)',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 200, 'label' => 'Large (200 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                ],
            ],
            [
                'name' => 'Dining Room',
                'icon' => 'rectangle-stack',
                'description' => 'Dining room with walls, ceiling, baseboards, and door',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 200, 'label' => 'Large (200 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $doorRate?->id],
                ],
            ],
            [
                'name' => 'Hallway',
                'icon' => 'arrow-long-right',
                'description' => 'Hallway with walls, ceiling, and baseboards',
                'sizes' => [
                    'small' => ['sqft' => 60, 'label' => 'Short (60 sqft)'],
                    'medium' => ['sqft' => 100, 'label' => 'Medium (100 sqft)'],
                    'large' => ['sqft' => 150, 'label' => 'Long (150 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                ],
            ],
            [
                'name' => 'Office',
                'icon' => 'computer-desktop',
                'description' => 'Home office with walls, ceiling, baseboards, and door',
                'sizes' => [
                    'small' => ['sqft' => 80, 'label' => 'Small (80 sqft)'],
                    'medium' => ['sqft' => 120, 'label' => 'Medium (120 sqft)'],
                    'large' => ['sqft' => 180, 'label' => 'Large (180 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $doorRate?->id],
                ],
            ],
            [
                'name' => 'Laundry',
                'icon' => 'bolt',
                'description' => 'Laundry room with walls, ceiling, baseboards, and door',
                'sizes' => [
                    'small' => ['sqft' => 40, 'label' => 'Small (40 sqft)'],
                    'medium' => ['sqft' => 60, 'label' => 'Medium (60 sqft)'],
                    'large' => ['sqft' => 80, 'label' => 'Large (80 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                    'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $trimRate?->id],
                    'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $doorRate?->id],
                ],
            ],
            [
                'name' => 'Closet',
                'icon' => 'archive-box',
                'description' => 'Walk-in closet with walls and ceiling',
                'sizes' => [
                    'small' => ['sqft' => 25, 'label' => 'Small (25 sqft)'],
                    'medium' => ['sqft' => 40, 'label' => 'Standard (40 sqft)'],
                    'large' => ['sqft' => 60, 'label' => 'Walk-in (60 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $wallPaintingRate?->id],
                    'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $ceilingRate?->id],
                ],
            ],
        ];

        foreach ($rooms as $index => $room) {
            // Get default size sqft for the template
            $defaultSizeSqft = $room['sizes'][$room['default_size']]['sqft'];

            $this->createTemplate($category, [
                'name' => $room['name'],
                'description' => $room['description'],
                'icon' => $room['icon'],
                'default_quantity' => $defaultSizeSqft,
                'default_unit' => 'sqft',
                'default_coats' => 2,
                'production_rate_id' => $wallPaintingRate?->id,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'pricing_method' => $wallPaintingRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_PER_SQFT,
                'per_sqft_rate' => $wallPaintingRate ? null : 2.50,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'default_surfaces' => array_keys($room['surface_ratios']),
                'metadata' => [
                    'sizes' => $room['sizes'],
                    'default_size' => $room['default_size'],
                    'surface_ratios' => $room['surface_ratios'],
                ],
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Create exterior room templates with full PBP relationships.
     *
     * Each exterior area includes:
     * - Size options (small/medium/large) for quick selection
     * - Surface ratios for auto-calculating siding, trim, fascia, etc.
     * - Production rate links for PBP pricing
     */
    private function createExteriorRoomTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $sidingPaintingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        // Get additional production rates for exterior surfaces
        $exteriorTrimRate = ProductionRate::where('name', 'Exterior Trim')->first();
        $deckStainingRate = ProductionRate::where('name', 'Deck Staining')->first();

        $exteriorAreas = [
            [
                'name' => 'House Exterior',
                'icon' => 'home',
                'description' => 'Full house exterior with siding, trim, fascia, and soffit',
                'sizes' => [
                    'small' => ['sqft' => 1500, 'label' => 'Small (1500 sqft)'],
                    'medium' => ['sqft' => 2500, 'label' => 'Medium (2500 sqft)'],
                    'large' => ['sqft' => 4000, 'label' => 'Large (4000 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $sidingPaintingRate?->id],
                    'fascia' => ['type' => 'lnft', 'per_100sqft' => 10, 'rate_id' => $exteriorTrimRate?->id],
                    'soffit' => ['type' => 'sqft', 'ratio' => 0.15, 'rate_id' => $sidingPaintingRate?->id],
                    'trim' => ['type' => 'lnft', 'per_100sqft' => 20, 'rate_id' => $exteriorTrimRate?->id],
                ],
            ],
            [
                'name' => 'Garage',
                'icon' => 'building-office',
                'description' => 'Garage exterior with siding and trim',
                'sizes' => [
                    'small' => ['sqft' => 400, 'label' => '1-Car (400 sqft)'],
                    'medium' => ['sqft' => 600, 'label' => '2-Car (600 sqft)'],
                    'large' => ['sqft' => 800, 'label' => '3-Car (800 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $sidingPaintingRate?->id],
                    'trim' => ['type' => 'lnft', 'per_100sqft' => 15, 'rate_id' => $exteriorTrimRate?->id],
                ],
            ],
            [
                'name' => 'Deck',
                'icon' => 'square-2-stack',
                'description' => 'Deck surface with railing',
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                    'medium' => ['sqft' => 250, 'label' => 'Medium (250 sqft)'],
                    'large' => ['sqft' => 400, 'label' => 'Large (400 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'deck_surface' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $deckStainingRate?->id],
                    'railing' => ['type' => 'lnft', 'per_100sqft' => 40, 'rate_id' => $exteriorTrimRate?->id],
                ],
            ],
            [
                'name' => 'Fence',
                'icon' => 'view-columns',
                'description' => 'Fence (both sides)',
                'sizes' => [
                    'small' => ['lnft' => 100, 'label' => 'Short (100 lnft)'],
                    'medium' => ['lnft' => 200, 'label' => 'Medium (200 lnft)'],
                    'large' => ['lnft' => 400, 'label' => 'Long (400 lnft)'],
                ],
                'default_size' => 'medium',
                'unit' => 'lnft',
                'surface_ratios' => [
                    // Fence: 6ft height × 2 sides = 12 sqft per lnft
                    'fence_both_sides' => ['type' => 'sqft', 'per_lnft' => 12, 'rate_id' => $sidingPaintingRate?->id],
                ],
            ],
            [
                'name' => 'Porch',
                'icon' => 'home',
                'description' => 'Covered porch with ceiling and columns',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 200, 'label' => 'Medium (200 sqft)'],
                    'large' => ['sqft' => 350, 'label' => 'Large (350 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'ceiling' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $sidingPaintingRate?->id],
                    'columns' => ['type' => 'unit', 'per_100sqft' => 2, 'rate_id' => $exteriorTrimRate?->id],
                    'railing' => ['type' => 'lnft', 'per_100sqft' => 30, 'rate_id' => $exteriorTrimRate?->id],
                ],
            ],
            [
                'name' => 'Shed',
                'icon' => 'cube',
                'description' => 'Outdoor shed exterior',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 200, 'label' => 'Medium (200 sqft)'],
                    'large' => ['sqft' => 300, 'label' => 'Large (300 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $sidingPaintingRate?->id],
                    'trim' => ['type' => 'lnft', 'per_100sqft' => 20, 'rate_id' => $exteriorTrimRate?->id],
                ],
            ],
        ];

        foreach ($exteriorAreas as $index => $area) {
            // Get default size value
            $defaultSize = $area['sizes'][$area['default_size']];
            $defaultUnit = $area['unit'] ?? 'sqft';
            $defaultQuantity = $defaultSize[$defaultUnit] ?? $defaultSize['sqft'] ?? 0;

            $this->createTemplate($category, [
                'name' => $area['name'],
                'description' => $area['description'],
                'icon' => $area['icon'],
                'default_quantity' => $defaultQuantity,
                'default_unit' => $defaultUnit,
                'default_coats' => 2,
                'production_rate_id' => $sidingPaintingRate?->id,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'pricing_method' => $sidingPaintingRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_PER_SQFT,
                'per_sqft_rate' => $sidingPaintingRate ? null : 2.00,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                'default_surfaces' => array_keys($area['surface_ratios']),
                'metadata' => [
                    'sizes' => $area['sizes'],
                    'default_size' => $area['default_size'],
                    'surface_ratios' => $area['surface_ratios'],
                ],
                'sort_order' => $index + 50,
            ]);
        }
    }

    /**
     * Create interior surface templates with full PBP relationships.
     */
    private function createInteriorSurfaceTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $trimPaintingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        $surfaces = [
            ['name' => 'Interior Door (Standard)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Standard interior door (both sides)'],
            ['name' => 'Interior Door (6-Panel)', 'quantity' => 1, 'unit' => 'unit', 'description' => '6-panel interior door (both sides)'],
            ['name' => 'Door Frame/Casing', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Door frame and casing'],
            ['name' => 'Window Casing', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Window frame and casing'],
            ['name' => 'Baseboards (per room)', 'quantity' => 50, 'unit' => 'lnft', 'description' => 'Baseboards per average room (50 linear feet)'],
            ['name' => 'Crown Molding (per room)', 'quantity' => 50, 'unit' => 'lnft', 'description' => 'Crown molding per average room (50 linear feet)'],
            ['name' => 'Chair Rail (per room)', 'quantity' => 50, 'unit' => 'lnft', 'description' => 'Chair rail per average room (50 linear feet)'],
            ['name' => 'Wainscoting (per wall)', 'quantity' => 40, 'unit' => 'sqft', 'description' => 'Wainscoting panel per wall (40 sqft average)'],
            ['name' => 'Fireplace Mantel', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Fireplace mantel and surround'],
            ['name' => 'Stair Railing (Interior)', 'quantity' => 20, 'unit' => 'lnft', 'description' => 'Interior stair railing (20 LF average)'],
            ['name' => 'Newel Post', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Newel post at stair landing'],
            ['name' => 'Spindles/Balusters (set)', 'quantity' => 15, 'unit' => 'unit', 'description' => 'Set of 15 stair balusters'],
        ];

        foreach ($surfaces as $index => $surface) {
            $this->createTemplate($category, [
                'name' => $surface['name'],
                'description' => $surface['description'],
                'default_quantity' => $surface['quantity'],
                'default_unit' => $surface['unit'],
                'default_coats' => 2,
                'production_rate_id' => $trimPaintingRate?->id,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'pricing_method' => $trimPaintingRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_FIXED,
                'base_price' => $trimPaintingRate ? null : 75,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Create exterior surface templates with full PBP relationships.
     */
    private function createExteriorSurfaceTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $exteriorTrimRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        $surfaces = [
            ['name' => 'Fascia Board', 'quantity' => 100, 'unit' => 'lnft', 'description' => 'Fascia board (100 linear feet)'],
            ['name' => 'Soffit', 'quantity' => 100, 'unit' => 'lnft', 'description' => 'Soffit (100 linear feet)'],
            ['name' => 'Gutters', 'quantity' => 100, 'unit' => 'lnft', 'description' => 'Gutters and downspouts (100 linear feet)'],
            ['name' => 'Exterior Door (Entry)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Entry door (both sides)'],
            ['name' => 'Exterior Door (Service)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Service/side door (both sides)'],
            ['name' => 'Garage Door (Single)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Single garage door (exterior face)'],
            ['name' => 'Garage Door (Double)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Double garage door (exterior face)'],
            ['name' => 'Window Trim (Exterior)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Single window exterior trim'],
            ['name' => 'Shutters (Pair)', 'quantity' => 2, 'unit' => 'unit', 'description' => 'Pair of exterior shutters'],
            ['name' => 'Porch Column', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Porch column'],
            ['name' => 'Porch Railing', 'quantity' => 20, 'unit' => 'lnft', 'description' => 'Porch railing (20 linear feet)'],
            ['name' => 'Deck Railing', 'quantity' => 30, 'unit' => 'lnft', 'description' => 'Deck railing (30 linear feet)'],
            ['name' => 'Deck Stairs', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Set of deck stairs'],
        ];

        foreach ($surfaces as $index => $surface) {
            $this->createTemplate($category, [
                'name' => $surface['name'],
                'description' => $surface['description'],
                'default_quantity' => $surface['quantity'],
                'default_unit' => $surface['unit'],
                'default_coats' => 2,
                'production_rate_id' => $exteriorTrimRate?->id,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'pricing_method' => $exteriorTrimRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_FIXED,
                'base_price' => $exteriorTrimRate ? null : 100,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                'sort_order' => $index + 50,
            ]);
        }
    }

    /**
     * Create interior item templates (cabinets, specialty work).
     */
    private function createInteriorItemTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $cabinetPaintingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        $items = [
            ['name' => 'Kitchen Cabinets (Small)', 'quantity' => 30, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Small kitchen cabinets (10-15 doors)'],
            ['name' => 'Kitchen Cabinets (Medium)', 'quantity' => 50, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Medium kitchen cabinets (15-25 doors)'],
            ['name' => 'Kitchen Cabinets (Large)', 'quantity' => 80, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Large kitchen cabinets (25+ doors)'],
            ['name' => 'Bathroom Vanity', 'quantity' => 15, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Bathroom vanity cabinet'],
            ['name' => 'Built-in Bookcase', 'quantity' => 40, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Built-in bookcase or shelving unit'],
            ['name' => 'Entertainment Center', 'quantity' => 50, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Built-in entertainment center'],
            ['name' => 'Accent Wall', 'quantity' => 120, 'unit' => 'sqft', 'use_rate' => false, 'per_sqft' => 3.50, 'description' => 'Single accent wall with contrasting color'],
            ['name' => 'Ceiling (Popcorn Removal)', 'quantity' => 200, 'unit' => 'sqft', 'use_rate' => false, 'per_sqft' => 2.00, 'description' => 'Popcorn ceiling removal and repaint'],
        ];

        foreach ($items as $index => $item) {
            $templateData = [
                'name' => $item['name'],
                'description' => $item['description'],
                'default_quantity' => $item['quantity'],
                'default_unit' => $item['unit'],
                'default_coats' => 2,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'sort_order' => $index + 1,
            ];

            if ($item['use_rate'] && $cabinetPaintingRate) {
                $templateData['production_rate_id'] = $cabinetPaintingRate->id;
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PRODUCTION_RATE;
            } elseif (isset($item['per_sqft'])) {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PER_SQFT;
                $templateData['per_sqft_rate'] = $item['per_sqft'];
            } else {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = 200;
            }

            $this->createTemplate($category, $templateData);
        }
    }

    /**
     * Create exterior item templates (decks, specialty work).
     */
    private function createExteriorItemTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $deckStainingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        $items = [
            ['name' => 'Pergola/Arbor', 'quantity' => 100, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Pergola or arbor staining'],
            ['name' => 'Outdoor Furniture Set', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 200, 'description' => 'Outdoor table and chairs'],
            ['name' => 'Mailbox Post', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 75, 'description' => 'Wooden mailbox post'],
            ['name' => 'Playset/Swingset', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 450, 'description' => 'Outdoor playset or swingset'],
            ['name' => 'Lattice Panel', 'quantity' => 32, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Lattice panel (4x8)'],
            ['name' => 'Window Box Planter', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 45, 'description' => 'Window box planter'],
        ];

        foreach ($items as $index => $item) {
            $templateData = [
                'name' => $item['name'],
                'description' => $item['description'],
                'default_quantity' => $item['quantity'],
                'default_unit' => $item['unit'],
                'default_coats' => 2,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                'sort_order' => $index + 50,
            ];

            if ($item['use_rate'] && $deckStainingRate) {
                $templateData['production_rate_id'] = $deckStainingRate->id;
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PRODUCTION_RATE;
            } elseif (isset($item['fixed_price'])) {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = $item['fixed_price'];
            } else {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = 200;
            }

            $this->createTemplate($category, $templateData);
        }
    }

    /**
     * Create add-on templates (warranties, services).
     */
    private function createAddonTemplates(EstimateTemplateCategory $category): void
    {
        $addons = [
            ['name' => '1-Year Touch-Up Warranty', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 150, 'description' => 'One year touch-up warranty covering minor chips and scratches'],
            ['name' => '3-Year Warranty', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 350, 'description' => 'Three year warranty on all painted surfaces'],
            ['name' => '5-Year Warranty', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 500, 'description' => 'Five year comprehensive warranty on all work'],
            ['name' => 'Premium Paint Upgrade', 'pricing_method' => EstimateTemplate::PRICING_PER_SQFT, 'per_sqft_rate' => 0.75, 'description' => 'Upgrade to premium paint (+$0.75/sqft)'],
            ['name' => 'Furniture Moving', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 150, 'description' => 'Move and protect furniture during painting'],
            ['name' => 'Color Consultation', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 100, 'description' => 'Professional color consultation with designer'],
            ['name' => 'Wallpaper Removal', 'pricing_method' => EstimateTemplate::PRICING_PER_SQFT, 'per_sqft_rate' => 2.50, 'description' => 'Wallpaper removal and wall prep (+$2.50/sqft)'],
            ['name' => 'Drywall Repair (Minor)', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 75, 'description' => 'Minor drywall repairs (holes, dings)'],
            ['name' => 'Drywall Repair (Major)', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 200, 'description' => 'Major drywall repairs (large holes, water damage)'],
            ['name' => 'Pressure Washing', 'pricing_method' => EstimateTemplate::PRICING_PER_SQFT, 'per_sqft_rate' => 0.25, 'description' => 'Exterior pressure washing before painting (+$0.25/sqft)'],
            ['name' => 'Lead Paint Testing', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 50, 'description' => 'Lead paint testing for pre-1978 homes'],
            ['name' => 'Expedited Service', 'pricing_method' => EstimateTemplate::PRICING_PERCENT, 'percent_of_total' => 15, 'description' => 'Rush service (+15% of total)'],
        ];

        foreach ($addons as $index => $addon) {
            $this->createTemplate($category, [
                'name' => $addon['name'],
                'description' => $addon['description'],
                'pricing_method' => $addon['pricing_method'],
                'base_price' => $addon['base_price'] ?? null,
                'per_sqft_rate' => $addon['per_sqft_rate'] ?? null,
                'percent_of_total' => $addon['percent_of_total'] ?? null,
                'default_quantity' => 1,
                'default_unit' => 'unit',
                'scope_type' => EstimateTemplate::SCOPE_TYPE_BOTH,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Create or update a template.
     *
     * @param  array<string, mixed>  $data
     */
    private function createTemplate(EstimateTemplateCategory $category, array $data): EstimateTemplate
    {
        return EstimateTemplate::updateOrCreate(
            [
                'template_category_id' => $category->id,
                'name' => $data['name'],
            ],
            array_merge($data, [
                'template_category_id' => $category->id,
                'is_active' => true,
            ])
        );
    }
}
