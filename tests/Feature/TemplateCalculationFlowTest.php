<?php

use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use App\Services\EstimateTemplateService;

beforeEach(function () {
    // Create required production rate category
    $this->category = ProductionRateCategory::factory()->create([
        'name' => 'Interior',
        'type' => 'interior',
    ]);

    // Create production rate for templates
    $this->productionRate = ProductionRate::factory()->create([
        'category_id' => $this->category->id,
        'name' => 'Wall Painting',
        'production_rate' => 200,
    ]);

    // Create substrate for testing
    $this->substrate = Substrate::factory()->create([
        'name' => 'Previously Painted - Good',
        'code' => 'PREV-GOOD-INT',
        'scope_type' => 'interior',
        'prep_time_multiplier' => 1.0,
        'paint_time_multiplier' => 1.0,
        'material_multiplier' => 1.0,
        'is_active' => true,
    ]);

    // Create substrate with higher multipliers
    $this->difficultSubstrate = Substrate::factory()->create([
        'name' => 'New Drywall',
        'code' => 'NEW-DRY-INT',
        'scope_type' => 'interior',
        'prep_time_multiplier' => 1.5,
        'paint_time_multiplier' => 1.2,
        'material_multiplier' => 1.3,
        'is_active' => true,
    ]);

    // Create paint brand and product
    $this->paintBrand = PaintBrand::factory()->create([
        'name' => 'Sherwin-Williams',
        'code' => 'SW',
    ]);

    $this->paintProduct = PaintProduct::factory()->create([
        'paint_brand_id' => $this->paintBrand->id,
        'name' => 'Duration Interior',
        'code' => 'SW-DURATION-INT',
        'scope_type' => 'interior',
        'sheen' => 'eggshell',
        'price_per_gallon' => 75.00,
        'coverage_sqft_brush_roll' => 400,
        'is_active' => true,
    ]);

    // Create template category
    $this->templateCategory = EstimateTemplateCategory::factory()->create([
        'name' => 'Rooms',
        'type' => EstimateTemplateCategory::TYPE_ROOM,
    ]);

    // Create estimate
    $this->estimate = Estimate::factory()->create();

    // Get service
    $this->service = new EstimateTemplateService;
});

describe('Template with substrate configuration', function () {
    it('applies substrate from template to created area', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'substrate_id' => $this->substrate->id,
            'name' => 'Standard Bedroom',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls', 'ceiling'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            150, // sqft
            2    // coats
        );

        expect($area->substrate_id)->toBe($this->substrate->id);
        expect($area->substrate->name)->toBe('Previously Painted - Good');
    });

    it('auto-selects substrate when template has none', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'substrate_id' => null,
            'name' => 'Standard Bedroom',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls', 'ceiling'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            150,
            2
        );

        // Should auto-select a PREV-GOOD substrate for interior
        expect($area->substrate_id)->not->toBeNull();
    });
});

describe('Template with paint product configuration', function () {
    it('creates paint selections from template default paint product', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'substrate_id' => $this->substrate->id,
            'default_paint_product_id' => $this->paintProduct->id,
            'name' => 'Standard Bedroom',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls', 'ceiling'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            150,
            2
        );

        $paintSelections = $area->paintSelections;

        expect($paintSelections)->toHaveCount(2); // walls and ceiling

        foreach ($paintSelections as $selection) {
            expect($selection->paint_product_id)->toBe($this->paintProduct->id);
            expect($selection->coats)->toBe(2);
            expect((float) $selection->sqft_covered)->toBeGreaterThan(0);
        }
    });

    it('calculates paint cost for each surface', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'default_paint_product_id' => $this->paintProduct->id,
            'name' => 'Standard Bedroom',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            100, // 10x10 room
            2
        );

        $paintSelection = $area->paintSelections->first();

        expect($paintSelection)->not->toBeNull();
        expect((float) $paintSelection->gallons_needed)->toBeGreaterThan(0);
        expect((float) $paintSelection->paint_cost)->toBeGreaterThan(0);
        expect((float) $paintSelection->total_material_cost)->toBeGreaterThan(0);
    });
});

describe('Scope type flow', function () {
    it('applies scope type from template to area', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'name' => 'Standard Bedroom',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            150,
            2
        );

        expect($area->scope_type)->toBe('interior');
    });

    it('defaults to interior when template has no scope', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'name' => 'Standard Area',
            'scope_type' => null,
            'default_surfaces' => ['walls'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            150,
            2
        );

        expect($area->scope_type)->toBe('interior');
    });
});

describe('Substrate multipliers affect calculations', function () {
    it('adjusts production rate based on substrate paint multiplier', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'substrate_id' => $this->difficultSubstrate->id, // 1.2x paint time
            'name' => 'New Drywall Room',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $area = $this->service->createAreaFromTemplate(
            $this->estimate,
            $template,
            100,
            2
        );

        $surface = $area->surfaces->first();

        // Original rate is 200 sqft/hr, divided by 1.2 multiplier = ~166.67
        expect((float) $surface->production_rate_value)->toBeLessThan(200);
        expect((float) $surface->production_rate_value)->toBeGreaterThan(165);
        expect((float) $surface->production_rate_value)->toBeLessThan(168);
    });
});

describe('Full template application flow', function () {
    it('creates complete area with all configurations', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'substrate_id' => $this->substrate->id,
            'default_paint_product_id' => $this->paintProduct->id,
            'name' => 'Master Bedroom',
            'description' => 'Large master bedroom with ensuite closet',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls', 'ceiling'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $usage = $this->service->applyTemplateToEstimate(
            $this->estimate,
            $template,
            250, // sqft
            2    // coats
        );

        $area = $usage->resultable;

        // Verify area was created
        expect($area)->not->toBeNull();
        expect($area->name)->toBe('Master Bedroom');
        expect($area->scope_type)->toBe('interior');
        expect($area->substrate_id)->toBe($this->substrate->id);

        // Verify surfaces were created
        expect($area->surfaces)->toHaveCount(2);

        // Verify paint selections were created
        expect($area->paintSelections)->toHaveCount(2);

        // Verify usage record
        expect($usage->estimate_id)->toBe($this->estimate->id);
        expect($usage->template_id)->toBe($template->id);
        expect((float) $usage->quantity)->toBe(250.0);
        expect($usage->coats)->toBe(2);
    });

    it('tracks template usage correctly', function () {
        $template = EstimateTemplate::factory()->create([
            'template_category_id' => $this->templateCategory->id,
            'production_rate_id' => $this->productionRate->id,
            'name' => 'Test Room',
            'scope_type' => 'interior',
            'default_surfaces' => ['walls'],
            'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
        ]);

        $usage = $this->service->applyTemplateToEstimate(
            $this->estimate,
            $template,
            100,
            2
        );

        expect($usage->resultable_type)->toBe(\App\Models\EstimateArea::class);
        expect($usage->resultable_id)->not->toBeNull();
        expect((float) $usage->calculated_price)->toBeGreaterThan(0);
    });
});
