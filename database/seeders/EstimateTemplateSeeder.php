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
     * Production rate cache to avoid repeated queries.
     */
    private array $rateCache = [];

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
        // Cache production rates for surface assignments
        $this->cacheProductionRates();

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

        // NOTE: Add-ons (warranties, services, prep work) are now in NestedAreaTemplateSeeder
        // as Fixed Price Templates. This keeps EstimateTemplates focused on
        // room/area templates that use production rates and size options.
    }

    /**
     * Cache production rates for quick lookup.
     */
    private function cacheProductionRates(): void
    {
        $rates = ProductionRate::all();
        foreach ($rates as $rate) {
            $this->rateCache[$rate->name] = $rate->id;
            // Also cache by ID for reverse lookup
            $this->rateCache['id_'.$rate->id] = $rate->name;
        }
    }

    /**
     * Get production rate ID by name, with fallback.
     */
    private function getRate(string $name, ?int $fallback = null): ?int
    {
        return $this->rateCache[$name] ?? $fallback;
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
     * Create interior room templates with comprehensive surface definitions.
     *
     * Each room includes:
     * - Size options (small/medium/large) for quick selection
     * - Surface ratios for auto-calculating walls, ceiling, baseboards, doors
     * - Production rate links for PBP pricing
     * - Optional add-ons (closet, crown molding)
     */
    private function createInteriorRoomTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $wallPaintingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        $rooms = [
            // ============================================
            // LIVING SPACES
            // ============================================
            [
                'name' => 'Living Room',
                'icon' => 'tv',
                'description' => 'Living room: walls, ceiling, baseboards, crown molding (optional), doors',
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                    'medium' => ['sqft' => 225, 'label' => 'Medium (225 sqft)'],
                    'large' => ['sqft' => 350, 'label' => 'Large (350 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling (9ft standard)',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards around perimeter',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior doors (both sides)',
                    ],
                ],
                'optional_surfaces' => [
                    'crown_molding' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'fixed_price' => 3.50,
                        'description' => 'Crown molding (per linear foot)',
                    ],
                    'accent_wall' => [
                        'type' => 'sqft',
                        'ratio' => 0.25,
                        'fixed_price' => 2.50,
                        'description' => 'Accent wall (different color)',
                    ],
                ],
            ],
            [
                'name' => 'Family Room',
                'icon' => 'users',
                'description' => 'Family room: walls, ceiling, baseboards, doors, fireplace mantel (optional)',
                'sizes' => [
                    'small' => ['sqft' => 200, 'label' => 'Small (200 sqft)'],
                    'medium' => ['sqft' => 300, 'label' => 'Medium (300 sqft)'],
                    'large' => ['sqft' => 450, 'label' => 'Large (450 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior doors',
                    ],
                ],
                'optional_surfaces' => [
                    'fireplace_mantel' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 125.00,
                        'description' => 'Fireplace mantel and surround',
                    ],
                    'built_in_shelves' => [
                        'type' => 'sqft',
                        'ratio' => 0.15,
                        'fixed_price' => 4.00,
                        'description' => 'Built-in shelving/entertainment center',
                    ],
                ],
            ],
            [
                'name' => 'Great Room',
                'icon' => 'building-library',
                'description' => 'Great room: tall walls, vaulted ceiling, baseboards, doors',
                'sizes' => [
                    'small' => ['sqft' => 300, 'label' => 'Small (300 sqft)'],
                    'medium' => ['sqft' => 450, 'label' => 'Medium (450 sqft)'],
                    'large' => ['sqft' => 600, 'label' => 'Large (600 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.4,  // Taller walls (12-14ft ceilings)
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Tall walls (12-14ft)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Vaulted or > 18ft ceilings change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Vaulted ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 25,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior doors',
                    ],
                ],
                'optional_surfaces' => [
                    'crown_molding' => [
                        'type' => 'lnft',
                        'per_100sqft' => 25,
                        'fixed_price' => 4.50,
                        'description' => 'Crown molding (high ceiling premium)',
                    ],
                ],
            ],
            [
                'name' => 'Dining Room',
                'icon' => 'rectangle-stack',
                'description' => 'Dining room: walls, ceiling, baseboards, chair rail (optional), doors',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 225, 'label' => 'Large (225 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 30,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior doors',
                    ],
                ],
                'optional_surfaces' => [
                    'chair_rail' => [
                        'type' => 'lnft',
                        'per_100sqft' => 30,
                        'fixed_price' => 2.75,
                        'description' => 'Chair rail molding',
                    ],
                    'wainscoting' => [
                        'type' => 'sqft',
                        'ratio' => 0.35,
                        'fixed_price' => 5.00,
                        'description' => 'Wainscoting panels',
                    ],
                    'crown_molding' => [
                        'type' => 'lnft',
                        'per_100sqft' => 30,
                        'fixed_price' => 3.50,
                        'description' => 'Crown molding',
                    ],
                ],
            ],
            [
                'name' => 'Sunroom',
                'icon' => 'sun',
                'description' => 'Sunroom: walls (reduced for windows), ceiling, baseboards, window trim',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 225, 'label' => 'Large (225 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.4,  // Less wall due to many windows
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces (limited by windows)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'window_trim' => [
                        'type' => 'unit',
                        'per_100sqft' => 4,
                        'fixed_price' => 35.00,
                        'description' => 'Window casing/trim (many windows)',
                    ],
                ],
            ],
            [
                'name' => 'Bonus Room',
                'icon' => 'puzzle-piece',
                'description' => 'Bonus room: walls, ceiling (may be angled), baseboards, doors',
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                    'medium' => ['sqft' => 250, 'label' => 'Medium (250 sqft)'],
                    'large' => ['sqft' => 400, 'label' => 'Large (400 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.85,  // Some knee walls may reduce area
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (may include knee walls)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,  // Angled ceilings add area
                        'rate_name' => 'Textured Vaulted or > 18ft ceilings change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling (often angled/vaulted)',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                ],
            ],

            // ============================================
            // BEDROOMS
            // ============================================
            [
                'name' => 'Bedroom',
                'icon' => 'home',
                'description' => 'Standard bedroom: walls, ceiling, baseboards, door, closet',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 200, 'label' => 'Large (200 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                    'closet' => [
                        'type' => 'fixed',
                        'price' => 95.00,
                        'description' => 'Standard closet (walls & ceiling)',
                    ],
                ],
            ],
            [
                'name' => 'Master Bedroom',
                'icon' => 'home-modern',
                'description' => 'Master bedroom: walls, ceiling, baseboards, doors (2), walk-in closet',
                'sizes' => [
                    'small' => ['sqft' => 175, 'label' => 'Small (175 sqft)'],
                    'medium' => ['sqft' => 250, 'label' => 'Medium (250 sqft)'],
                    'large' => ['sqft' => 350, 'label' => 'Large (350 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Entry door + closet door',
                    ],
                    'closet' => [
                        'type' => 'fixed',
                        'price' => 195.00,
                        'description' => 'Walk-in closet (walls & ceiling)',
                    ],
                ],
                'optional_surfaces' => [
                    'tray_ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.3,
                        'fixed_price' => 3.00,
                        'description' => 'Tray ceiling accent',
                    ],
                ],
            ],
            [
                'name' => 'Guest Room',
                'icon' => 'user-plus',
                'description' => 'Guest bedroom: walls, ceiling, baseboards, door, closet',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 140, 'label' => 'Medium (140 sqft)'],
                    'large' => ['sqft' => 180, 'label' => 'Large (180 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                    'closet' => [
                        'type' => 'fixed',
                        'price' => 95.00,
                        'description' => 'Standard closet',
                    ],
                ],
            ],
            [
                'name' => 'Nursery',
                'icon' => 'heart',
                'description' => 'Nursery: walls (often accent), ceiling, baseboards, door, closet',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 130, 'label' => 'Medium (130 sqft)'],
                    'large' => ['sqft' => 160, 'label' => 'Large (160 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Wall surfaces',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                    'closet' => [
                        'type' => 'fixed',
                        'price' => 75.00,
                        'description' => 'Small closet',
                    ],
                ],
                'optional_surfaces' => [
                    'accent_wall' => [
                        'type' => 'sqft',
                        'ratio' => 0.25,
                        'fixed_price' => 2.50,
                        'description' => 'Accent wall (different color)',
                    ],
                ],
            ],

            // ============================================
            // BATHROOMS
            // ============================================
            [
                'name' => 'Bathroom',
                'icon' => 'sparkles',
                'description' => 'Standard bathroom: walls (cut-in around fixtures), ceiling, baseboards, door',
                'sizes' => [
                    'small' => ['sqft' => 50, 'label' => 'Small (50 sqft)'],
                    'medium' => ['sqft' => 80, 'label' => 'Standard (80 sqft)'],
                    'large' => ['sqft' => 120, 'label' => 'Large (120 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.7,  // Less wall due to tile, mirrors, fixtures
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (cut-in around fixtures)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 25,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                ],
                'optional_surfaces' => [
                    'vanity_cabinet' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 175.00,
                        'description' => 'Bathroom vanity cabinet',
                    ],
                ],
            ],
            [
                'name' => 'Full Bathroom',
                'icon' => 'sparkles',
                'description' => 'Full bathroom: walls, ceiling, baseboards, door, window trim',
                'sizes' => [
                    'small' => ['sqft' => 60, 'label' => 'Small (60 sqft)'],
                    'medium' => ['sqft' => 85, 'label' => 'Standard (85 sqft)'],
                    'large' => ['sqft' => 120, 'label' => 'Large/Master (120 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.65,  // Tile, mirrors, fixtures reduce wall area
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (cut-in around fixtures)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 24,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                    'window_trim' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 35.00,
                        'description' => 'Window casing',
                    ],
                ],
            ],

            // ============================================
            // KITCHEN & UTILITY
            // ============================================
            [
                'name' => 'Kitchen',
                'icon' => 'fire',
                'description' => 'Kitchen: walls, ceiling, baseboards (cabinets priced separately)',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 175, 'label' => 'Medium (175 sqft)'],
                    'large' => ['sqft' => 275, 'label' => 'Large (275 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.5,  // Cabinets, backsplash, appliances reduce wall area
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (above cabinets, exposed areas)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 15,  // Less baseboards (covered by cabinets)
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards (exposed areas)',
                    ],
                ],
                'optional_surfaces' => [
                    'cabinets_small' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 1200.00,
                        'description' => 'Kitchen cabinets - Small (10-15 doors)',
                    ],
                    'cabinets_medium' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 1800.00,
                        'description' => 'Kitchen cabinets - Medium (15-25 doors)',
                    ],
                    'cabinets_large' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 2500.00,
                        'description' => 'Kitchen cabinets - Large (25+ doors)',
                    ],
                ],
            ],
            [
                'name' => 'Laundry',
                'icon' => 'bolt',
                'description' => 'Laundry room: walls, ceiling, baseboards, door, shelving',
                'sizes' => [
                    'small' => ['sqft' => 35, 'label' => 'Small (35 sqft)'],
                    'medium' => ['sqft' => 55, 'label' => 'Medium (55 sqft)'],
                    'large' => ['sqft' => 80, 'label' => 'Large (80 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.85,  // Washer/dryer reduce some wall area
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                ],
                'optional_surfaces' => [
                    'shelving' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'fixed_price' => 3.00,
                        'description' => 'Built-in shelving',
                    ],
                    'cabinets' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 350.00,
                        'description' => 'Laundry cabinets',
                    ],
                ],
            ],

            // ============================================
            // HALLWAYS & TRANSITIONS
            // ============================================
            [
                'name' => 'Hallway',
                'icon' => 'arrow-long-right',
                'description' => 'Hallway: walls, ceiling, baseboards, multiple doors',
                'sizes' => [
                    'small' => ['sqft' => 50, 'label' => 'Short (50 sqft)'],
                    'medium' => ['sqft' => 85, 'label' => 'Medium (85 sqft)'],
                    'large' => ['sqft' => 130, 'label' => 'Long (130 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.3,  // Hallways have more wall per sqft (narrow)
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (high ratio for narrow space)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 40,  // More baseboards per sqft (narrow)
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards (both sides)',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'per_100sqft' => 3,  // Doors leading off hallway
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Doors along hallway',
                    ],
                ],
            ],
            [
                'name' => 'Upper Hallway',
                'icon' => 'arrow-trending-up',
                'description' => 'Upstairs hallway: walls, ceiling, baseboards, doors, may include railing',
                'sizes' => [
                    'small' => ['sqft' => 45, 'label' => 'Small (45 sqft)'],
                    'medium' => ['sqft' => 75, 'label' => 'Medium (75 sqft)'],
                    'large' => ['sqft' => 120, 'label' => 'Large (120 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.2,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 35,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'per_100sqft' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Bedroom doors',
                    ],
                ],
                'optional_surfaces' => [
                    'railing' => [
                        'type' => 'lnft',
                        'per_100sqft' => 10,
                        'fixed_price' => 8.00,
                        'description' => 'Stair railing (if overlooking)',
                    ],
                ],
            ],
            [
                'name' => 'Entry Foyer',
                'icon' => 'arrow-right-start-on-rectangle',
                'description' => 'Entry foyer: walls, ceiling, baseboards, entry door trim, closet',
                'sizes' => [
                    'small' => ['sqft' => 40, 'label' => 'Small (40 sqft)'],
                    'medium' => ['sqft' => 70, 'label' => 'Medium (70 sqft)'],
                    'large' => ['sqft' => 110, 'label' => 'Large (110 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior side of front door',
                    ],
                    'closet' => [
                        'type' => 'fixed',
                        'price' => 95.00,
                        'description' => 'Coat closet',
                    ],
                ],
            ],
            [
                'name' => 'Entry Foyer 2-Story',
                'icon' => 'arrow-up-circle',
                'description' => 'Two-story foyer: tall walls, high ceiling, baseboards, entry door',
                'sizes' => [
                    'small' => ['sqft' => 60, 'label' => 'Small (60 sqft)'],
                    'medium' => ['sqft' => 100, 'label' => 'Medium (100 sqft)'],
                    'large' => ['sqft' => 160, 'label' => 'Large (160 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 2.2,  // Double height walls
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Tall walls (16-20ft)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Vaulted or > 18ft ceilings change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'High ceiling (requires scaffolding)',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 25,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Entry door (interior)',
                    ],
                ],
                'optional_surfaces' => [
                    'chandelier_area' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 75.00,
                        'description' => 'Work around chandelier',
                    ],
                ],
            ],
            [
                'name' => 'Stairwell',
                'icon' => 'arrows-up-down',
                'description' => 'Stairwell: tall walls along stairs, ceiling, railing',
                'sizes' => [
                    'small' => ['sqft' => 35, 'label' => 'Small (35 sqft)'],
                    'medium' => ['sqft' => 55, 'label' => 'Medium (55 sqft)'],
                    'large' => ['sqft' => 85, 'label' => 'Large (85 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 2.5,  // Very tall walls in stairwell
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Tall stairwell walls (scaffolding needed)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Vaulted or > 18ft ceilings change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling above stairs',
                    ],
                ],
                'optional_surfaces' => [
                    'railing' => [
                        'type' => 'lnft',
                        'per_100sqft' => 20,
                        'fixed_price' => 8.00,
                        'description' => 'Stair railing/handrail',
                    ],
                    'spindles' => [
                        'type' => 'unit',
                        'per_100sqft' => 15,
                        'fixed_price' => 5.00,
                        'description' => 'Spindles/balusters',
                    ],
                    'newel_posts' => [
                        'type' => 'unit',
                        'count' => 2,
                        'fixed_price' => 45.00,
                        'description' => 'Newel posts',
                    ],
                ],
            ],

            // ============================================
            // UTILITY & STORAGE
            // ============================================
            [
                'name' => 'Mudroom',
                'icon' => 'arrow-right-end-on-rectangle',
                'description' => 'Mudroom: walls, ceiling, baseboards, doors, hooks/cubbies',
                'sizes' => [
                    'small' => ['sqft' => 35, 'label' => 'Small (35 sqft)'],
                    'medium' => ['sqft' => 55, 'label' => 'Medium (55 sqft)'],
                    'large' => ['sqft' => 90, 'label' => 'Large (90 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.75,  // Built-ins, hooks reduce wall area
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (around cubbies)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 25,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 2,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Doors (entry & interior)',
                    ],
                ],
                'optional_surfaces' => [
                    'cubbies' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 150.00,
                        'description' => 'Built-in cubbies/bench',
                    ],
                ],
            ],
            [
                'name' => 'Closet',
                'icon' => 'archive-box',
                'description' => 'Closet: walls, ceiling (no baseboards typically)',
                'sizes' => [
                    'small' => ['sqft' => 20, 'label' => 'Small Reach-In (20 sqft)'],
                    'medium' => ['sqft' => 35, 'label' => 'Standard (35 sqft)'],
                    'large' => ['sqft' => 55, 'label' => 'Walk-In (55 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.2,  // Tight space = more wall per sqft
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                ],
                'optional_surfaces' => [
                    'shelving' => [
                        'type' => 'lnft',
                        'per_100sqft' => 15,
                        'fixed_price' => 3.00,
                        'description' => 'Built-in shelving',
                    ],
                    'closet_doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 85.00,
                        'description' => 'Bi-fold closet doors',
                    ],
                ],
            ],
            [
                'name' => 'Office',
                'icon' => 'computer-desktop',
                'description' => 'Home office: walls, ceiling, baseboards, door, built-ins optional',
                'sizes' => [
                    'small' => ['sqft' => 80, 'label' => 'Small (80 sqft)'],
                    'medium' => ['sqft' => 120, 'label' => 'Medium (120 sqft)'],
                    'large' => ['sqft' => 180, 'label' => 'Large (180 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Textured Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Textured Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 28,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior door',
                    ],
                    'closet' => [
                        'type' => 'fixed',
                        'price' => 95.00,
                        'description' => 'Standard closet',
                    ],
                ],
                'optional_surfaces' => [
                    'built_in_desk' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 200.00,
                        'description' => 'Built-in desk area',
                    ],
                    'bookcase' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 250.00,
                        'description' => 'Built-in bookcase',
                    ],
                ],
            ],
            [
                'name' => 'Basement',
                'icon' => 'cube-transparent',
                'description' => 'Finished basement: walls, ceiling, baseboards (often more sqft)',
                'sizes' => [
                    'small' => ['sqft' => 400, 'label' => 'Small (400 sqft)'],
                    'medium' => ['sqft' => 700, 'label' => 'Medium (700 sqft)'],
                    'large' => ['sqft' => 1100, 'label' => 'Large (1100 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'walls' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,  // Lower ceilings = less wall
                        'rate_name' => 'Smooth Walls change color',
                        'rate_fallback' => 'Wall Painting',
                        'description' => 'Walls (may have shorter ceiling)',
                    ],
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 0.9,
                        'rate_name' => 'Smooth Ceiling change color',
                        'rate_fallback' => 'Ceiling Painting',
                        'description' => 'Ceiling',
                    ],
                    'baseboards' => [
                        'type' => 'lnft',
                        'per_100sqft' => 22,
                        'rate_name' => 'Baseboards change color',
                        'rate_fallback' => 'Trim Painting',
                        'description' => 'Baseboards',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'per_100sqft' => 0.5,
                        'rate_name' => 'Doors both sides',
                        'rate_fallback' => 'Door Painting',
                        'description' => 'Interior doors',
                    ],
                ],
                'optional_surfaces' => [
                    'stairwell' => [
                        'type' => 'fixed',
                        'price' => 275.00,
                        'description' => 'Basement stairwell',
                    ],
                    'bathroom' => [
                        'type' => 'fixed',
                        'price' => 350.00,
                        'description' => 'Basement bathroom',
                    ],
                ],
            ],
        ];

        foreach ($rooms as $index => $room) {
            // Get default size sqft for the template
            $defaultSizeSqft = $room['sizes'][$room['default_size']]['sqft'];

            // Resolve production rate IDs in surface_ratios
            $surfaceRatios = $this->resolveSurfaceRateIds($room['surface_ratios'], $wallPaintingRate?->id);
            $optionalSurfaces = isset($room['optional_surfaces'])
                ? $this->resolveSurfaceRateIds($room['optional_surfaces'], $wallPaintingRate?->id)
                : null;

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
                'default_surfaces' => array_keys($surfaceRatios),
                'metadata' => [
                    'sizes' => $room['sizes'],
                    'default_size' => $room['default_size'],
                    'surface_ratios' => $surfaceRatios,
                    'optional_surfaces' => $optionalSurfaces,
                ],
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Resolve production rate names to IDs in surface ratios.
     */
    private function resolveSurfaceRateIds(array $surfaces, ?int $fallbackId): array
    {
        foreach ($surfaces as $key => &$surface) {
            if (isset($surface['rate_name'])) {
                $rateId = $this->getRate($surface['rate_name']);
                if (! $rateId && isset($surface['rate_fallback'])) {
                    $rateId = $this->getRate($surface['rate_fallback']);
                }
                $surface['rate_id'] = $rateId ?? $fallbackId;
            }
        }

        return $surfaces;
    }

    /**
     * Create exterior room templates with comprehensive surface definitions.
     */
    private function createExteriorRoomTemplates(
        EstimateTemplateCategory $category,
        ?ProductionRate $sidingPaintingRate,
        ?Substrate $substrate,
        ?PaintProduct $paintProduct
    ): void {
        $exteriorAreas = [
            // ============================================
            // WHOLE HOUSE EXTERIOR
            // ============================================
            [
                'name' => 'House Exterior',
                'icon' => 'home',
                'description' => 'Full house exterior: siding, fascia, soffit, trim, windows, doors',
                'sizes' => [
                    'small' => ['sqft' => 1500, 'label' => 'Small (1500 sqft)'],
                    'medium' => ['sqft' => 2500, 'label' => 'Medium (2500 sqft)'],
                    'large' => ['sqft' => 4000, 'label' => 'Large (4000 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding (body)',
                    ],
                    'fascia' => [
                        'type' => 'lnft',
                        'per_100sqft' => 10,
                        'rate_name' => 'Fascia on 2 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Fascia board',
                    ],
                    'soffit' => [
                        'type' => 'lnft',
                        'per_100sqft' => 10,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Soffit/eaves',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 18,
                        'rate_name' => 'Other trim on 2+ story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Window/door trim',
                    ],
                    'windows' => [
                        'type' => 'unit',
                        'per_100sqft' => 1.5,
                        'rate_name' => 'Windows Vinyl with trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Window trim',
                    ],
                    'doors' => [
                        'type' => 'unit',
                        'per_100sqft' => 0.15,
                        'rate_name' => 'Door Body Color with Trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Exterior doors',
                    ],
                ],
                'optional_surfaces' => [
                    'shutters' => [
                        'type' => 'unit',
                        'per_100sqft' => 1,
                        'fixed_price' => 45.00,
                        'description' => 'Shutters (per pair)',
                    ],
                    'gutters' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'fixed_price' => 1.50,
                        'description' => 'Gutters/downspouts',
                    ],
                    'garage_doors' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 185.00,
                        'description' => 'Garage door',
                    ],
                ],
            ],

            // ============================================
            // INDIVIDUAL HOUSE SIDES
            // ============================================
            [
                'name' => 'Front of House',
                'icon' => 'eye',
                'description' => 'Front elevation: siding, fascia, soffit, trim, entry door',
                'sizes' => [
                    'small' => ['sqft' => 400, 'label' => 'Small (400 sqft)'],
                    'medium' => ['sqft' => 650, 'label' => 'Medium (650 sqft)'],
                    'large' => ['sqft' => 950, 'label' => 'Large (950 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding (body)',
                    ],
                    'fascia' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Fascia on 2 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Fascia',
                    ],
                    'soffit' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Soffit',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 20,
                        'rate_name' => 'Other trim on 2+ story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'windows' => [
                        'type' => 'unit',
                        'per_100sqft' => 2,
                        'rate_name' => 'Windows Vinyl with trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Window trim',
                    ],
                    'entry_door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Door Accent Color with Trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Entry door (accent color)',
                    ],
                ],
                'optional_surfaces' => [
                    'shutters' => [
                        'type' => 'unit',
                        'per_100sqft' => 1.5,
                        'fixed_price' => 45.00,
                        'description' => 'Shutters (per pair)',
                    ],
                    'columns' => [
                        'type' => 'unit',
                        'count' => 2,
                        'fixed_price' => 85.00,
                        'description' => 'Porch columns',
                    ],
                ],
            ],
            [
                'name' => 'Left Side of House',
                'icon' => 'arrow-left',
                'description' => 'Left elevation: siding, fascia, soffit, trim',
                'sizes' => [
                    'small' => ['sqft' => 350, 'label' => 'Small (350 sqft)'],
                    'medium' => ['sqft' => 550, 'label' => 'Medium (550 sqft)'],
                    'large' => ['sqft' => 850, 'label' => 'Large (850 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding',
                    ],
                    'fascia' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Fascia on 2 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Fascia',
                    ],
                    'soffit' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Soffit',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 15,
                        'rate_name' => 'Other trim on 2+ story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'windows' => [
                        'type' => 'unit',
                        'per_100sqft' => 1.2,
                        'rate_name' => 'Windows Vinyl with trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Window trim',
                    ],
                ],
            ],
            [
                'name' => 'Right Side of House',
                'icon' => 'arrow-right',
                'description' => 'Right elevation: siding, fascia, soffit, trim',
                'sizes' => [
                    'small' => ['sqft' => 350, 'label' => 'Small (350 sqft)'],
                    'medium' => ['sqft' => 550, 'label' => 'Medium (550 sqft)'],
                    'large' => ['sqft' => 850, 'label' => 'Large (850 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding',
                    ],
                    'fascia' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Fascia on 2 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Fascia',
                    ],
                    'soffit' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Soffit',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 15,
                        'rate_name' => 'Other trim on 2+ story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'windows' => [
                        'type' => 'unit',
                        'per_100sqft' => 1.2,
                        'rate_name' => 'Windows Vinyl with trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Window trim',
                    ],
                ],
            ],
            [
                'name' => 'Back of House',
                'icon' => 'eye-slash',
                'description' => 'Back elevation: siding, fascia, soffit, trim, patio door',
                'sizes' => [
                    'small' => ['sqft' => 400, 'label' => 'Small (400 sqft)'],
                    'medium' => ['sqft' => 650, 'label' => 'Medium (650 sqft)'],
                    'large' => ['sqft' => 950, 'label' => 'Large (950 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding',
                    ],
                    'fascia' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Fascia on 2 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Fascia',
                    ],
                    'soffit' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Soffit',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 20,
                        'rate_name' => 'Other trim on 2+ story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'windows' => [
                        'type' => 'unit',
                        'per_100sqft' => 1.8,
                        'rate_name' => 'Windows Vinyl with trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Window trim',
                    ],
                    'patio_door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'rate_name' => 'Door Body Color with Trim',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Patio/sliding door trim',
                    ],
                ],
            ],

            // ============================================
            // GARAGE & OUTBUILDINGS
            // ============================================
            [
                'name' => 'Garage',
                'icon' => 'building-office',
                'description' => 'Attached garage exterior: siding, trim, garage door(s)',
                'sizes' => [
                    'small' => ['sqft' => 350, 'label' => '1-Car (350 sqft)'],
                    'medium' => ['sqft' => 550, 'label' => '2-Car (550 sqft)'],
                    'large' => ['sqft' => 800, 'label' => '3-Car (800 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 15,
                        'rate_name' => 'Other trim on 1 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'garage_door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 185.00,
                        'description' => 'Garage door (double)',
                    ],
                ],
                'optional_surfaces' => [
                    'service_door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 95.00,
                        'description' => 'Service/side door',
                    ],
                    'window' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 65.00,
                        'description' => 'Garage window',
                    ],
                ],
            ],
            [
                'name' => 'Detached Garage',
                'icon' => 'building-office-2',
                'description' => 'Detached garage: all 4 sides, siding, trim, doors',
                'sizes' => [
                    'small' => ['sqft' => 500, 'label' => '1-Car (500 sqft)'],
                    'medium' => ['sqft' => 750, 'label' => '2-Car (750 sqft)'],
                    'large' => ['sqft' => 1000, 'label' => '3-Car (1000 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray w/light backroll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding (all 4 sides)',
                    ],
                    'fascia' => [
                        'type' => 'lnft',
                        'per_100sqft' => 10,
                        'rate_name' => 'Fascia on 1 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Fascia',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 18,
                        'rate_name' => 'Other trim on 1 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'garage_door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 185.00,
                        'description' => 'Garage door',
                    ],
                    'service_door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 95.00,
                        'description' => 'Service door',
                    ],
                ],
            ],
            [
                'name' => 'Shed',
                'icon' => 'cube',
                'description' => 'Outdoor shed: siding, trim, door',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 175, 'label' => 'Medium (175 sqft)'],
                    'large' => ['sqft' => 275, 'label' => 'Large (275 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'siding' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Roll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Siding',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 20,
                        'rate_name' => 'Other trim on 1 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Trim',
                    ],
                    'door' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 65.00,
                        'description' => 'Shed door',
                    ],
                ],
            ],

            // ============================================
            // PORCHES & COVERED AREAS
            // ============================================
            [
                'name' => 'Porch',
                'icon' => 'home',
                'description' => 'Covered porch: ceiling, columns, railing',
                'sizes' => [
                    'small' => ['sqft' => 80, 'label' => 'Small (80 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 250, 'label' => 'Large (250 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Porch ceiling',
                    ],
                    'columns' => [
                        'type' => 'unit',
                        'per_100sqft' => 2,
                        'fixed_price' => 65.00,
                        'description' => 'Porch columns',
                    ],
                    'railing' => [
                        'type' => 'lnft',
                        'per_100sqft' => 25,
                        'rate_name' => 'Railings replacement',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Porch railing',
                    ],
                ],
                'optional_surfaces' => [
                    'floor' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'fixed_price' => 2.00,
                        'description' => 'Porch floor (if painting)',
                    ],
                ],
            ],
            [
                'name' => 'Wrap-Around Porch',
                'icon' => 'arrows-right-left',
                'description' => 'Wrap-around porch: large ceiling, many columns, long railing',
                'sizes' => [
                    'small' => ['sqft' => 200, 'label' => 'Small (200 sqft)'],
                    'medium' => ['sqft' => 350, 'label' => 'Medium (350 sqft)'],
                    'large' => ['sqft' => 550, 'label' => 'Large (550 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Porch ceiling',
                    ],
                    'columns' => [
                        'type' => 'unit',
                        'per_100sqft' => 3,
                        'fixed_price' => 65.00,
                        'description' => 'Porch columns',
                    ],
                    'railing' => [
                        'type' => 'lnft',
                        'per_100sqft' => 40,
                        'rate_name' => 'Railings replacement',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Railing (longer perimeter)',
                    ],
                ],
            ],
            [
                'name' => 'Screened Porch',
                'icon' => 'window',
                'description' => 'Screened porch: ceiling, trim around screens',
                'sizes' => [
                    'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                    'medium' => ['sqft' => 175, 'label' => 'Medium (175 sqft)'],
                    'large' => ['sqft' => 275, 'label' => 'Large (275 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Porch ceiling',
                    ],
                    'trim' => [
                        'type' => 'lnft',
                        'per_100sqft' => 40,
                        'rate_name' => 'Other trim on 1 story home',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Screen frame trim',
                    ],
                ],
            ],
            [
                'name' => 'Carport',
                'icon' => 'truck',
                'description' => 'Carport: ceiling, columns',
                'sizes' => [
                    'small' => ['sqft' => 200, 'label' => '1-Car (200 sqft)'],
                    'medium' => ['sqft' => 350, 'label' => '2-Car (350 sqft)'],
                    'large' => ['sqft' => 500, 'label' => '3-Car (500 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'ceiling' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Eaves Body Color',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Carport ceiling',
                    ],
                    'columns' => [
                        'type' => 'unit',
                        'per_100sqft' => 1.5,
                        'fixed_price' => 65.00,
                        'description' => 'Support columns',
                    ],
                ],
            ],

            // ============================================
            // DECKS & FENCES
            // ============================================
            [
                'name' => 'Deck',
                'icon' => 'square-2-stack',
                'description' => 'Deck: deck surface, railing, stairs',
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                    'medium' => ['sqft' => 275, 'label' => 'Medium (275 sqft)'],
                    'large' => ['sqft' => 450, 'label' => 'Large (450 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'deck_surface' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Deck Staining',
                        'rate_fallback' => 'Deck Staining',
                        'description' => 'Deck boards',
                    ],
                    'railing' => [
                        'type' => 'lnft',
                        'per_100sqft' => 30,
                        'rate_name' => 'Railings replacement',
                        'rate_fallback' => 'Exterior Trim',
                        'description' => 'Deck railing',
                    ],
                ],
                'optional_surfaces' => [
                    'stairs' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 125.00,
                        'description' => 'Deck stairs',
                    ],
                    'bench' => [
                        'type' => 'lnft',
                        'per_100sqft' => 8,
                        'fixed_price' => 5.00,
                        'description' => 'Built-in bench',
                    ],
                ],
            ],
            [
                'name' => 'Fence',
                'icon' => 'view-columns',
                'description' => 'Fence: boards (both sides)',
                'sizes' => [
                    'small' => ['lnft' => 100, 'label' => 'Short (100 lnft)'],
                    'medium' => ['lnft' => 200, 'label' => 'Medium (200 lnft)'],
                    'large' => ['lnft' => 400, 'label' => 'Long (400 lnft)'],
                ],
                'default_size' => 'medium',
                'unit' => 'lnft',
                'surface_ratios' => [
                    'fence_both_sides' => [
                        'type' => 'sqft',
                        'per_lnft' => 12,  // 6ft height × 2 sides
                        'rate_name' => 'Siding Roll',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Fence boards (both sides)',
                    ],
                ],
                'optional_surfaces' => [
                    'gate' => [
                        'type' => 'unit',
                        'count' => 1,
                        'fixed_price' => 75.00,
                        'description' => 'Fence gate',
                    ],
                    'posts' => [
                        'type' => 'unit',
                        'per_100sqft' => 3,
                        'fixed_price' => 15.00,
                        'description' => 'Fence posts',
                    ],
                ],
            ],

            // ============================================
            // SPECIALTY EXTERIOR
            // ============================================
            [
                'name' => 'Pergola/Arbor',
                'icon' => 'squares-plus',
                'description' => 'Pergola or arbor: beams, rafters, posts',
                'sizes' => [
                    'small' => ['sqft' => 80, 'label' => 'Small (80 sqft)'],
                    'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                    'large' => ['sqft' => 250, 'label' => 'Large (250 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'structure' => [
                        'type' => 'sqft',
                        'ratio' => 1.5,  // Open structure has more surface area
                        'rate_name' => 'Deck Staining',
                        'rate_fallback' => 'Deck Staining',
                        'description' => 'Beams and rafters',
                    ],
                    'posts' => [
                        'type' => 'unit',
                        'per_100sqft' => 2.5,
                        'fixed_price' => 45.00,
                        'description' => 'Support posts',
                    ],
                ],
            ],
            [
                'name' => 'Lattice Panel',
                'icon' => 'squares-plus',
                'description' => 'Lattice panel(s): detailed brush/spray work',
                'sizes' => [
                    'small' => ['sqft' => 32, 'label' => '1 Panel (32 sqft)'],
                    'medium' => ['sqft' => 64, 'label' => '2 Panels (64 sqft)'],
                    'large' => ['sqft' => 128, 'label' => '4 Panels (128 sqft)'],
                ],
                'default_size' => 'medium',
                'surface_ratios' => [
                    'lattice' => [
                        'type' => 'sqft',
                        'ratio' => 1.0,
                        'rate_name' => 'Siding Spray',
                        'rate_fallback' => 'Siding Painting',
                        'description' => 'Lattice panels',
                    ],
                ],
            ],
        ];

        foreach ($exteriorAreas as $index => $area) {
            // Get default size value
            $defaultSize = $area['sizes'][$area['default_size']];
            $defaultUnit = $area['unit'] ?? 'sqft';
            $defaultQuantity = $defaultSize[$defaultUnit] ?? $defaultSize['sqft'] ?? 0;

            // Resolve production rate IDs in surface_ratios
            $surfaceRatios = $this->resolveSurfaceRateIds($area['surface_ratios'], $sidingPaintingRate?->id);
            $optionalSurfaces = isset($area['optional_surfaces'])
                ? $this->resolveSurfaceRateIds($area['optional_surfaces'], $sidingPaintingRate?->id)
                : null;

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
                'default_surfaces' => array_keys($surfaceRatios),
                'metadata' => [
                    'sizes' => $area['sizes'],
                    'default_size' => $area['default_size'],
                    'surface_ratios' => $surfaceRatios,
                    'optional_surfaces' => $optionalSurfaces,
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
            ['name' => 'Interior Door (Standard)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Doors both sides', 'description' => 'Standard interior door (both sides)'],
            ['name' => 'Interior Door (6-Panel)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Doors both sides', 'description' => '6-panel interior door (both sides)'],
            ['name' => 'Door Frame/Casing', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Baseboards change color', 'description' => 'Door frame and casing'],
            ['name' => 'Window Casing', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Baseboards change color', 'description' => 'Window frame and casing'],
            ['name' => 'Baseboards (per room)', 'quantity' => 50, 'unit' => 'lnft', 'rate_name' => 'Baseboards change color', 'description' => 'Baseboards per average room (50 linear feet)'],
            ['name' => 'Crown Molding (per room)', 'quantity' => 50, 'unit' => 'lnft', 'rate_name' => 'Baseboards change color', 'description' => 'Crown molding per average room (50 linear feet)'],
            ['name' => 'Chair Rail (per room)', 'quantity' => 50, 'unit' => 'lnft', 'rate_name' => 'Baseboards change color', 'description' => 'Chair rail per average room (50 linear feet)'],
            ['name' => 'Wainscoting (per wall)', 'quantity' => 40, 'unit' => 'sqft', 'rate_name' => 'Smooth Walls change color', 'description' => 'Wainscoting panel per wall (40 sqft average)'],
            ['name' => 'Fireplace Mantel', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Baseboards change color', 'description' => 'Fireplace mantel and surround'],
            ['name' => 'Stair Railing (Interior)', 'quantity' => 20, 'unit' => 'lnft', 'rate_name' => 'Baseboards change color', 'description' => 'Interior stair railing (20 LF average)'],
            ['name' => 'Newel Post', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Baseboards change color', 'description' => 'Newel post at stair landing'],
            ['name' => 'Spindles/Balusters (set)', 'quantity' => 15, 'unit' => 'unit', 'rate_name' => 'Baseboards change color', 'description' => 'Set of 15 stair balusters'],
        ];

        foreach ($surfaces as $index => $surface) {
            $rateId = $this->getRate($surface['rate_name']) ?? $trimPaintingRate?->id;

            $this->createTemplate($category, [
                'name' => $surface['name'],
                'description' => $surface['description'],
                'default_quantity' => $surface['quantity'],
                'default_unit' => $surface['unit'],
                'default_coats' => 2,
                'production_rate_id' => $rateId,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'pricing_method' => $rateId ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_FIXED,
                'base_price' => $rateId ? null : 75,
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
            ['name' => 'Fascia Board', 'quantity' => 100, 'unit' => 'lnft', 'rate_name' => 'Fascia on 2 story home', 'description' => 'Fascia board (100 linear feet)'],
            ['name' => 'Soffit', 'quantity' => 100, 'unit' => 'lnft', 'rate_name' => 'Eaves Body Color', 'description' => 'Soffit (100 linear feet)'],
            ['name' => 'Gutters', 'quantity' => 100, 'unit' => 'lnft', 'rate_name' => 'Downspouts or Posts with 3-4 sides', 'description' => 'Gutters and downspouts (100 linear feet)'],
            ['name' => 'Exterior Door (Entry)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Door Accent Color with Trim', 'description' => 'Entry door (both sides)'],
            ['name' => 'Exterior Door (Service)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Door Body Color with Trim', 'description' => 'Service/side door (both sides)'],
            ['name' => 'Garage Door (Single)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Door Body Color with no trim', 'description' => 'Single garage door (exterior face)'],
            ['name' => 'Garage Door (Double)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Door Body Color with no trim', 'description' => 'Double garage door (exterior face)'],
            ['name' => 'Window Trim (Exterior)', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Windows Vinyl with trim', 'description' => 'Single window exterior trim'],
            ['name' => 'Shutters (Pair)', 'quantity' => 2, 'unit' => 'unit', 'rate_name' => 'Other trim on 1 story home', 'description' => 'Pair of exterior shutters'],
            ['name' => 'Porch Column', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Downspouts or Posts with 3-4 sides', 'description' => 'Porch column'],
            ['name' => 'Porch Railing', 'quantity' => 20, 'unit' => 'lnft', 'rate_name' => 'Railings replacement', 'description' => 'Porch railing (20 linear feet)'],
            ['name' => 'Deck Railing', 'quantity' => 30, 'unit' => 'lnft', 'rate_name' => 'Railings replacement', 'description' => 'Deck railing (30 linear feet)'],
            ['name' => 'Deck Stairs', 'quantity' => 1, 'unit' => 'unit', 'rate_name' => 'Deck Staining', 'description' => 'Set of deck stairs'],
        ];

        foreach ($surfaces as $index => $surface) {
            $rateId = $this->getRate($surface['rate_name']) ?? $exteriorTrimRate?->id;

            $this->createTemplate($category, [
                'name' => $surface['name'],
                'description' => $surface['description'],
                'default_quantity' => $surface['quantity'],
                'default_unit' => $surface['unit'],
                'default_coats' => 2,
                'production_rate_id' => $rateId,
                'substrate_id' => $substrate?->id,
                'default_paint_product_id' => $paintProduct?->id,
                'pricing_method' => $rateId ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_FIXED,
                'base_price' => $rateId ? null : 100,
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
            ['name' => 'Lattice Panel', 'quantity' => 32, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Lattice panel (4x8)'],
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
            } else {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = 200;
            }

            $this->createTemplate($category, $templateData);
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
                'is_configurable' => true,
            ])
        );
    }
}
