<?php

use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use Database\Seeders\EstimateTemplateSeeder;
use Database\Seeders\NestedAreaTemplateSeeder;
use Database\Seeders\PaintCatalogSeeder;
use Database\Seeders\SubstrateSeeder;

beforeEach(function () {
    // Clean up any existing data
    EstimateTemplate::query()->delete();
    EstimateTemplateCategory::query()->delete();
});

describe('EstimateTemplateSeeder', function () {
    it('creates template categories', function () {
        (new EstimateTemplateSeeder)->run();

        expect(EstimateTemplateCategory::where('name', 'Rooms')->exists())->toBeTrue();
        expect(EstimateTemplateCategory::where('name', 'Surfaces')->exists())->toBeTrue();
        expect(EstimateTemplateCategory::where('name', 'Items')->exists())->toBeTrue();
        expect(EstimateTemplateCategory::where('name', 'Add-ons')->exists())->toBeTrue();
    });

    it('creates interior room templates', function () {
        (new EstimateTemplateSeeder)->run();

        $interiorRooms = EstimateTemplate::where('scope_type', 'interior')
            ->whereHas('templateCategory', fn ($q) => $q->where('type', 'room'))
            ->get();

        expect($interiorRooms->count())->toBeGreaterThanOrEqual(10);

        // Check for specific common rooms
        $roomNames = $interiorRooms->pluck('name')->toArray();
        expect($roomNames)->toContain('Bedroom');
        expect($roomNames)->toContain('Master Bedroom');
        expect($roomNames)->toContain('Bathroom');
        expect($roomNames)->toContain('Kitchen');
        expect($roomNames)->toContain('Living Room');
        expect($roomNames)->toContain('Dining Room');
        expect($roomNames)->toContain('Hallway');
    });

    it('creates exterior room templates', function () {
        (new EstimateTemplateSeeder)->run();

        $exteriorAreas = EstimateTemplate::where('scope_type', 'exterior')
            ->whereHas('templateCategory', fn ($q) => $q->where('type', 'room'))
            ->get();

        expect($exteriorAreas->count())->toBeGreaterThanOrEqual(5);

        // Check for specific exterior areas
        $areaNames = $exteriorAreas->pluck('name')->toArray();
        expect($areaNames)->toContain('House Exterior');
        expect($areaNames)->toContain('Garage');
        expect($areaNames)->toContain('Deck');
        expect($areaNames)->toContain('Fence');
    });

    it('creates add-ons as Fixed Price templates in NestedAreaTemplates', function () {
        // Add-ons (warranties, services, prep) are now in NestedAreaTemplateSeeder
        (new NestedAreaTemplateSeeder)->run();

        $addons = NestedAreaTemplate::where('scope_type', 'both')->get();

        expect($addons->count())->toBeGreaterThanOrEqual(5);

        // Check for specific add-ons
        $addonNames = $addons->pluck('name')->toArray();
        expect($addonNames)->toContain('1-Year Touch-Up Warranty');
        expect($addonNames)->toContain('Color Consultation');
        expect($addonNames)->toContain('Premium Paint Upgrade');
    });

    it('sets default surfaces for room templates', function () {
        (new EstimateTemplateSeeder)->run();

        $bedroom = EstimateTemplate::where('name', 'Bedroom')->first();

        expect($bedroom)->not->toBeNull();
        expect($bedroom->default_surfaces)->toContain('walls');
        expect($bedroom->default_surfaces)->toContain('ceiling');
    });

    it('is idempotent and can be run multiple times', function () {
        // Run seeder twice
        (new EstimateTemplateSeeder)->run();
        $countAfterFirst = EstimateTemplate::count();

        (new EstimateTemplateSeeder)->run();
        $countAfterSecond = EstimateTemplate::count();

        // Count should be the same (updateOrCreate behavior)
        expect($countAfterFirst)->toBe($countAfterSecond);
    });
});

describe('EstimateTemplateSeeder with relationships', function () {
    beforeEach(function () {
        // First seed substrates and paint products
        (new SubstrateSeeder)->run();
        (new PaintCatalogSeeder)->run();

        // Create production rates for the templates
        $interiorCategory = ProductionRateCategory::factory()->create(['type' => 'interior']);
        $exteriorCategory = ProductionRateCategory::factory()->create(['type' => 'exterior']);

        ProductionRate::factory()->create([
            'category_id' => $interiorCategory->id,
            'name' => 'Wall Painting',
            'production_rate' => 300,
        ]);
        ProductionRate::factory()->create([
            'category_id' => $interiorCategory->id,
            'name' => 'Trim Painting',
            'production_rate' => 100,
        ]);
        ProductionRate::factory()->create([
            'category_id' => $interiorCategory->id,
            'name' => 'Cabinet Painting',
            'production_rate' => 50,
        ]);
        ProductionRate::factory()->create([
            'category_id' => $exteriorCategory->id,
            'name' => 'Siding Painting',
            'production_rate' => 200,
        ]);
        ProductionRate::factory()->create([
            'category_id' => $exteriorCategory->id,
            'name' => 'Exterior Trim',
            'production_rate' => 80,
        ]);
        ProductionRate::factory()->create([
            'category_id' => $exteriorCategory->id,
            'name' => 'Deck Staining',
            'production_rate' => 150,
        ]);
    });

    it('links interior templates to interior substrates', function () {
        (new EstimateTemplateSeeder)->run();

        $bedroom = EstimateTemplate::where('name', 'Bedroom')->first();

        expect($bedroom)->not->toBeNull();
        expect($bedroom->substrate_id)->not->toBeNull();

        $substrate = Substrate::find($bedroom->substrate_id);
        expect($substrate)->not->toBeNull();
        expect($substrate->scope_type)->toBeIn(['interior', 'both']);
    });

    it('links exterior templates to exterior substrates', function () {
        (new EstimateTemplateSeeder)->run();

        $exterior = EstimateTemplate::where('name', 'House Exterior')->first();

        expect($exterior)->not->toBeNull();
        expect($exterior->substrate_id)->not->toBeNull();

        $substrate = Substrate::find($exterior->substrate_id);
        expect($substrate)->not->toBeNull();
        expect($substrate->scope_type)->toBeIn(['exterior', 'both']);
    });

    it('links interior templates to interior paint products', function () {
        (new EstimateTemplateSeeder)->run();

        $bedroom = EstimateTemplate::where('name', 'Bedroom')->first();

        expect($bedroom)->not->toBeNull();
        expect($bedroom->default_paint_product_id)->not->toBeNull();

        $paint = PaintProduct::find($bedroom->default_paint_product_id);
        expect($paint)->not->toBeNull();
        expect($paint->scope_type)->toBeIn(['interior', 'both']);
    });

    it('links exterior templates to exterior paint products', function () {
        (new EstimateTemplateSeeder)->run();

        $exterior = EstimateTemplate::where('name', 'House Exterior')->first();

        expect($exterior)->not->toBeNull();
        expect($exterior->default_paint_product_id)->not->toBeNull();

        $paint = PaintProduct::find($exterior->default_paint_product_id);
        expect($paint)->not->toBeNull();
        expect($paint->scope_type)->toBeIn(['exterior', 'both']);
    });

    it('links room templates to production rates', function () {
        (new EstimateTemplateSeeder)->run();

        $bedroom = EstimateTemplate::where('name', 'Bedroom')->first();

        expect($bedroom)->not->toBeNull();
        expect($bedroom->production_rate_id)->not->toBeNull();
        expect($bedroom->pricing_method)->toBe('production_rate');

        $rate = ProductionRate::find($bedroom->production_rate_id);
        expect($rate)->not->toBeNull();
    });

    it('creates templates with complete PBP data for 5-minute workflow', function () {
        (new EstimateTemplateSeeder)->run();

        // Check that we have templates with all required relationships
        $completeTemplates = EstimateTemplate::whereNotNull('substrate_id')
            ->whereNotNull('default_paint_product_id')
            ->whereNotNull('production_rate_id')
            ->where('pricing_method', 'production_rate')
            ->count();

        // Should have multiple complete templates
        expect($completeTemplates)->toBeGreaterThanOrEqual(5);
    });

    it('add-ons are now in NestedAreaTemplates with fixed pricing', function () {
        // Add-ons moved to NestedAreaTemplates (Fixed Price) for simpler workflow
        (new NestedAreaTemplateSeeder)->run();

        // Fixed price warranty
        $warranty = NestedAreaTemplate::where('name', '1-Year Touch-Up Warranty')->first();
        expect($warranty)->not->toBeNull();
        expect((float) $warranty->fixed_price)->toBeGreaterThan(0);

        // Fixed price services
        $paintUpgrade = NestedAreaTemplate::where('name', 'Premium Paint Upgrade')->first();
        expect($paintUpgrade)->not->toBeNull();
        expect((float) $paintUpgrade->fixed_price)->toBeGreaterThan(0);

        // Prep work
        $drywall = NestedAreaTemplate::where('code', 'PREP-DRYWALL-SM')->first();
        expect($drywall)->not->toBeNull();
        expect((float) $drywall->fixed_price)->toBeGreaterThan(0);
    });
});
