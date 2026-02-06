<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimateOptionalItem;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\EstimateTemplateUsage;
use App\Models\ProductionRate;
use App\Services\EstimateTemplateService;
use App\Services\ProductionRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimateTemplateService;
});

describe('seedDefaultTemplates', function () {
    beforeEach(function () {
        // Seed production rates first as templates depend on them
        (new ProductionRateService)->seedDefaultRates();
    });

    it('creates all template categories', function () {
        $this->service->seedDefaultTemplates();

        expect(EstimateTemplateCategory::count())->toBe(4);
        expect(EstimateTemplateCategory::where('type', 'room')->exists())->toBeTrue();
        expect(EstimateTemplateCategory::where('type', 'surface')->exists())->toBeTrue();
        expect(EstimateTemplateCategory::where('type', 'item')->exists())->toBeTrue();
        expect(EstimateTemplateCategory::where('type', 'addon')->exists())->toBeTrue();
    });

    it('creates room templates with correct data', function () {
        $this->service->seedDefaultTemplates();

        $roomsCategory = EstimateTemplateCategory::where('type', 'room')->first();
        $templates = EstimateTemplate::where('template_category_id', $roomsCategory->id)->get();

        // 8 interior rooms + 6 exterior areas = 14 total
        expect($templates)->toHaveCount(14);

        $bathroom = $templates->firstWhere('name', 'Standard Bathroom');
        expect($bathroom)->not->toBeNull()
            ->and($bathroom->default_quantity)->toBe('80.00')
            ->and($bathroom->default_unit)->toBe('sqft')
            ->and($bathroom->default_coats)->toBe(2);

        $livingRoom = $templates->firstWhere('name', 'Living Room');
        expect($livingRoom)->not->toBeNull()
            ->and($livingRoom->default_quantity)->toBe('300.00');
    });

    it('creates surface templates', function () {
        $this->service->seedDefaultTemplates();

        $surfacesCategory = EstimateTemplateCategory::where('type', 'surface')->first();
        $templates = EstimateTemplate::where('template_category_id', $surfacesCategory->id)->get();

        // 5 interior surfaces + 10 exterior surfaces = 15 total
        expect($templates)->toHaveCount(15);

        $door = $templates->firstWhere('name', 'Standard Door');
        expect($door)->not->toBeNull()
            ->and($door->default_quantity)->toBe('1.00')
            ->and($door->default_unit)->toBe('unit');

        $baseboards = $templates->firstWhere('name', 'Baseboards');
        expect($baseboards)->not->toBeNull()
            ->and($baseboards->default_quantity)->toBe('20.00')
            ->and($baseboards->default_unit)->toBe('lnft');
    });

    it('creates item templates', function () {
        $this->service->seedDefaultTemplates();

        $itemsCategory = EstimateTemplateCategory::where('type', 'item')->first();
        $templates = EstimateTemplate::where('template_category_id', $itemsCategory->id)->get();

        // 4 interior items + 7 exterior items = 11 total
        expect($templates)->toHaveCount(11);

        $cabinets = $templates->firstWhere('name', 'Kitchen Cabinets');
        expect($cabinets)->not->toBeNull()
            ->and($cabinets->default_quantity)->toBe('50.00')
            ->and($cabinets->default_unit)->toBe('sqft');

        $staircase = $templates->firstWhere('name', 'Staircase & Railing');
        expect($staircase)->not->toBeNull()
            ->and($staircase->pricing_method)->toBe(EstimateTemplate::PRICING_FIXED)
            ->and($staircase->base_price)->toBe('450.00');
    });

    it('creates add-on templates with correct pricing', function () {
        $this->service->seedDefaultTemplates();

        $addonsCategory = EstimateTemplateCategory::where('type', 'addon')->first();
        $templates = EstimateTemplate::where('template_category_id', $addonsCategory->id)->get();

        expect($templates)->toHaveCount(6);

        $warranty1 = $templates->firstWhere('name', '1-Year Warranty');
        expect($warranty1)->not->toBeNull()
            ->and($warranty1->pricing_method)->toBe(EstimateTemplate::PRICING_FIXED)
            ->and($warranty1->base_price)->toBe('150.00');

        $warranty5 = $templates->firstWhere('name', '5-Year Warranty');
        expect($warranty5)->not->toBeNull()
            ->and($warranty5->base_price)->toBe('500.00');

        $premiumPaint = $templates->firstWhere('name', 'Premium Paint Upgrade');
        expect($premiumPaint)->not->toBeNull()
            ->and($premiumPaint->pricing_method)->toBe(EstimateTemplate::PRICING_PER_SQFT)
            ->and($premiumPaint->per_sqft_rate)->toBe('2.0000');
    });

    it('is idempotent - does not duplicate templates on repeat calls', function () {
        $this->service->seedDefaultTemplates();
        $initialCount = EstimateTemplate::count();

        $this->service->seedDefaultTemplates();
        $finalCount = EstimateTemplate::count();

        expect($finalCount)->toBe($initialCount);
    });
});

describe('applyTemplateToEstimate', function () {
    it('creates EstimateArea for room templates', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ROOM]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Test Room',
            'default_quantity' => 150,
            'default_unit' => 'sqft',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 500,
        ]);

        $usage = $this->service->applyTemplateToEstimate($estimate, $template, 150, 2);

        expect($usage)->toBeInstanceOf(EstimateTemplateUsage::class)
            ->and($usage->estimate_id)->toBe($estimate->id)
            ->and($usage->template_id)->toBe($template->id)
            ->and($usage->quantity)->toBe('150.00')
            ->and($usage->coats)->toBe(2)
            ->and($usage->resultable_type)->toBe(EstimateArea::class);

        $area = EstimateArea::find($usage->resultable_id);
        expect($area)->not->toBeNull()
            ->and($area->name)->toBe('Test Room')
            ->and($area->estimate_id)->toBe($estimate->id);
    });

    it('creates EstimateItem for item templates', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ITEM]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Cabinet Work',
            'default_quantity' => 50,
            'default_unit' => 'sqft',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 750,
        ]);

        $usage = $this->service->applyTemplateToEstimate($estimate, $template, 50, 2);

        expect($usage->resultable_type)->toBe(EstimateItem::class);

        $item = EstimateItem::find($usage->resultable_id);
        expect($item)->not->toBeNull()
            ->and($item->description)->toBe('Cabinet Work')
            ->and($item->estimate_id)->toBe($estimate->id);
    });

    it('creates EstimateOptionalItem for addon templates', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ADDON]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Extended Warranty',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 350,
        ]);

        $usage = $this->service->applyTemplateToEstimate($estimate, $template, 1, 1);

        expect($usage->resultable_type)->toBe(EstimateOptionalItem::class);

        $optionalItem = EstimateOptionalItem::find($usage->resultable_id);
        expect($optionalItem)->not->toBeNull()
            ->and($optionalItem->name)->toBe('Extended Warranty')
            ->and($optionalItem->price)->toBe('350.00')
            ->and($optionalItem->is_selected_by_customer)->toBeFalse();
    });

    it('creates EstimateAreaSurface for surface templates when area provided', function () {
        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_SURFACE]);
        $productionRate = ProductionRate::factory()->create([
            'name' => 'Trim Painting',
            'unit_type' => 'lnft',
            'production_rate' => 100,
        ]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Baseboards',
            'production_rate_id' => $productionRate->id,
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
            'default_unit' => 'lnft',
        ]);

        $usage = $this->service->applyTemplateToEstimate($estimate, $template, 20, 2, $area);

        expect($usage->resultable_type)->toBe(EstimateAreaSurface::class);

        $surface = EstimateAreaSurface::find($usage->resultable_id);
        expect($surface)->not->toBeNull()
            ->and($surface->area_id)->toBe($area->id)
            ->and($surface->quantity)->toBe('20.00')
            ->and($surface->coats)->toBe(2);
    });

    it('returns null resultable for surface templates when no area provided', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_SURFACE]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Window Trim',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 75,
        ]);

        $usage = $this->service->applyTemplateToEstimate($estimate, $template, 1, 2);

        expect($usage->resultable_id)->toBeNull()
            ->and($usage->resultable_type)->toBeNull();
    });
});

describe('createAreaFromTemplate', function () {
    it('creates area with dimensions calculated from sqft', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ROOM]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Master Bedroom',
            'default_quantity' => 225,
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 600,
        ]);

        $area = $this->service->createAreaFromTemplate($estimate, $template, 225, 2);

        // sqrt(225) = 15, so we expect 15x15 room with 9ft height
        expect($area)->toBeInstanceOf(EstimateArea::class)
            ->and($area->name)->toBe('Master Bedroom')
            ->and($area->length)->toBe('15.00')
            ->and($area->width)->toBe('15.00')
            ->and($area->height)->toBe('9.00');
    });

    it('creates default surfaces when template has production rate and default_surfaces', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ROOM]);

        $productionRate = ProductionRate::factory()->create([
            'name' => 'Wall Painting',
            'unit_type' => 'sqft',
            'production_rate' => 300,
        ]);

        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Living Room',
            'production_rate_id' => $productionRate->id,
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
            'default_surfaces' => ['walls', 'ceiling'],
        ]);

        $area = $this->service->createAreaFromTemplate($estimate, $template, 100, 2);

        $surfaces = EstimateAreaSurface::where('area_id', $area->id)->get();

        expect($surfaces)->toHaveCount(2);
        expect($surfaces->pluck('name')->toArray())->toContain('Walls', 'Ceiling');
    });
});

describe('createItemFromTemplate', function () {
    it('creates item with calculated price', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ITEM]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Accent Wall',
            'default_unit' => 'sqft',
            'pricing_method' => EstimateTemplate::PRICING_PER_SQFT,
            'per_sqft_rate' => 3.50,
        ]);

        $item = $this->service->createItemFromTemplate($estimate, $template, 100, 2);

        // 100 sqft * $3.50 = $350 total, unit price = $3.50
        expect($item)->toBeInstanceOf(EstimateItem::class)
            ->and($item->description)->toBe('Accent Wall')
            ->and($item->quantity)->toBe('100.00')
            ->and($item->unit)->toBe('sqft')
            ->and($item->unit_price)->toBe('3.50');
    });

    it('creates item with fixed pricing', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ITEM]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Staircase Painting',
            'default_unit' => 'unit',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 450,
        ]);

        $item = $this->service->createItemFromTemplate($estimate, $template, 1, 2);

        expect($item->unit_price)->toBe('450.00')
            ->and($item->total)->toBe('450.00');
    });
});

describe('createOptionalItemFromTemplate', function () {
    it('creates optional item with fixed pricing', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ADDON]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => '3-Year Warranty',
            'description' => 'Extended warranty coverage',
            'pricing_method' => EstimateTemplate::PRICING_FIXED,
            'base_price' => 350,
        ]);

        $optionalItem = $this->service->createOptionalItemFromTemplate($estimate, $template, 1);

        expect($optionalItem)->toBeInstanceOf(EstimateOptionalItem::class)
            ->and($optionalItem->name)->toBe('3-Year Warranty')
            ->and($optionalItem->description)->toBe('Extended warranty coverage')
            ->and($optionalItem->pricing_type)->toBe('fixed')
            ->and($optionalItem->price)->toBe('350.00')
            ->and($optionalItem->template_id)->toBe($template->id);
    });

    it('creates optional item with per sqft pricing', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ADDON]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Premium Paint Upgrade',
            'pricing_method' => EstimateTemplate::PRICING_PER_SQFT,
            'per_sqft_rate' => 2.00,
        ]);

        $optionalItem = $this->service->createOptionalItemFromTemplate($estimate, $template, 500);

        // 500 sqft * $2.00 = $1000
        expect($optionalItem->pricing_type)->toBe('fixed')
            ->and($optionalItem->price)->toBe('1000.00');
    });

    it('creates optional item with percent pricing', function () {
        $estimate = Estimate::factory()->create();
        $category = EstimateTemplateCategory::factory()->create(['type' => EstimateTemplateCategory::TYPE_ADDON]);
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $category->id,
            'name' => 'Expedited Service',
            'pricing_method' => EstimateTemplate::PRICING_PERCENT,
            'percent_of_total' => 15,
        ]);

        $optionalItem = $this->service->createOptionalItemFromTemplate($estimate, $template, 1);

        expect($optionalItem->pricing_type)->toBe('percent')
            ->and($optionalItem->percent_of_total)->toBe('15.00');
    });
});

describe('integration with ProductionRateService', function () {
    it('seeds templates with fallback pricing when production rates are named differently', function () {
        // The seeder creates rates with descriptive names like "Textured Walls same color"
        // but seedDefaultTemplates looks for "Wall Painting" which won't match
        (new ProductionRateService)->seedDefaultRates();
        $this->service->seedDefaultTemplates();

        $roomsCategory = EstimateTemplateCategory::where('type', 'room')->first();
        $bedroomTemplate = EstimateTemplate::where('name', 'Standard Bedroom')->first();

        // Since "Wall Painting" doesn't exist, it falls back to per_sqft pricing
        expect($bedroomTemplate)->not->toBeNull()
            ->and($bedroomTemplate->pricing_method)->toBe(EstimateTemplate::PRICING_PER_SQFT)
            ->and($bedroomTemplate->per_sqft_rate)->toBe('2.5000');
    });
});
