<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Services\EstimateAiService;

beforeEach(function () {
    $this->service = new EstimateAiService;
    $this->estimate = Estimate::factory()->create([
        'status' => 'draft',
        'project_type' => 'interior',
    ]);

    // Ensure company settings exist
    CompanySetting::firstOrCreate([], [
        'company_name' => 'Test Painting Co',
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_margin_percent' => 50.00,
        'exterior_margin_percent' => 41.00,
        'interior_cleanup_hours_ratio' => 16.00,
        'exterior_cleanup_hours_ratio' => 20.00,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
        'recycle_fee_per_gallon' => 0.54,
        'material_tax_rate' => 7.50,
        'labor_tax_rate' => 0.00,
    ]);

    // Reset singleton cache
    CompanySetting::clearInstanceCache();
});

// =========================================================================
// DISCOVERY METHODS
// =========================================================================

it('searches templates by name', function () {
    $category = EstimateTemplateCategory::factory()->create(['name' => 'Interior']);
    EstimateTemplate::factory()->configurable()->interior()->forCategory($category)->create([
        'name' => 'Living Room',
        'is_active' => true,
    ]);
    EstimateTemplate::factory()->configurable()->interior()->forCategory($category)->create([
        'name' => 'Bedroom',
        'is_active' => true,
    ]);

    $results = $this->service->searchTemplates('Living');

    expect($results)->toHaveCount(1)
        ->and($results[0]['name'])->toBe('Living Room');
});

it('searches templates filtered by scope', function () {
    $category = EstimateTemplateCategory::factory()->create(['name' => 'Rooms']);
    EstimateTemplate::factory()->configurable()->interior()->forCategory($category)->create([
        'name' => 'Kitchen',
        'is_active' => true,
    ]);
    EstimateTemplate::factory()->configurable()->exterior()->forCategory($category)->create([
        'name' => 'Kitchen Exterior',
        'is_active' => true,
    ]);

    $results = $this->service->searchTemplates('Kitchen', 'interior');

    expect($results)->toHaveCount(1)
        ->and($results[0]['scope_type'])->toBe('interior');
});

it('lists production rates', function () {
    ProductionRate::factory()->create([
        'name' => 'Smooth Walls',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'unit_type' => 'sqft',
        'rate_type' => 'sqft_hr',
    ]);

    $rates = $this->service->listProductionRates('interior', 'walls');

    expect($rates)->not->toBeEmpty()
        ->and(collect($rates)->firstWhere('name', 'Smooth Walls'))->not->toBeNull();
});

it('lists paint products', function () {
    $brand = \App\Models\PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Duration',
        'scope_type' => 'interior',
        'quality_tier' => 'best',
        'is_active' => true,
    ]);

    $products = $this->service->listPaintProducts('interior', null, 'best');

    expect($products)->not->toBeEmpty()
        ->and(collect($products)->firstWhere('name', 'Duration'))->not->toBeNull();
});

it('lists substrates', function () {
    Substrate::factory()->create([
        'name' => 'Previously Painted',
        'scope_type' => 'both',
        'prep_time_multiplier' => 1.0,
        'paint_time_multiplier' => 1.0,
        'is_active' => true,
    ]);

    $substrates = $this->service->listSubstrates();

    expect($substrates)->not->toBeEmpty();
});

it('gets pricing config', function () {
    $config = $this->service->getPricingConfig();

    expect($config)->toHaveKeys(['interior', 'exterior', 'formula_explanation'])
        ->and($config['interior'])->toHaveKeys(['wage', 'effective_rate', 'margin_percent', 'cleanup_hours_ratio']);
});

// =========================================================================
// ESTIMATE-LEVEL METHODS
// =========================================================================

it('gets estimate state', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Living Room',
        'scope_type' => 'interior',
    ]);

    $state = $this->service->getEstimateState($this->estimate);

    expect($state['id'])->toBe($this->estimate->id)
        ->and($state['areas'])->toHaveCount(1)
        ->and($state['areas'][0]['name'])->toBe('Living Room');
});

it('updates estimate settings', function () {
    $result = $this->service->updateEstimateSettings($this->estimate, [
        'title' => 'Updated Title',
        'notes' => 'Test notes',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($this->estimate->fresh()->title)->toBe('Updated Title');
});

it('recalculates estimate totals', function () {
    $result = $this->service->recalculate($this->estimate);

    expect($result)->toHaveKeys(['subtotal', 'tax_amount', 'total', 'areas_count']);
});

// =========================================================================
// AREA CREATION
// =========================================================================

it('creates custom area and calculates dimensions', function () {
    $result = $this->service->createCustomArea(
        estimate: $this->estimate,
        name: 'Sun Room',
        scopeType: 'interior',
        length: 14,
        width: 12,
        height: 9,
        wallCount: 3
    );

    expect($result['area_id'])->toBeGreaterThan(0)
        ->and($result['name'])->toBe('Sun Room')
        ->and($result['wall_sqft'])->toBeGreaterThan(0)
        ->and($result['ceiling_sqft'])->toBe(168.0) // 14 * 12
        ->and($result['surfaces'])->toBeEmpty();
});

it('creates area from template with correct surface quantities', function () {
    $category = EstimateTemplateCategory::factory()->create(['name' => 'Interior']);
    $rate = ProductionRate::factory()->create([
        'name' => 'Smooth Walls',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'unit_type' => 'sqft',
        'rate_type' => 'sqft_hr',
        'default_coats' => 2,
    ]);

    $template = EstimateTemplate::factory()->configurable()->interior()->forCategory($category)
        ->withProductionRate($rate)
        ->create([
            'name' => 'Living Room',
            'metadata' => [
                'dimensions' => [
                    'small' => ['length' => 10, 'width' => 10, 'height' => 8, 'sqft' => 100],
                    'medium' => ['length' => 14, 'width' => 12, 'height' => 8, 'sqft' => 168],
                    'large' => ['length' => 18, 'width' => 14, 'height' => 9, 'sqft' => 252],
                ],
                'surface_ratios' => [
                    'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $rate->id],
                ],
            ],
        ]);

    $result = $this->service->createAreaFromTemplate(
        estimate: $this->estimate,
        templateId: $template->id,
        size: 'medium',
        coats: 2,
    );

    expect($result['area_id'])->toBeGreaterThan(0)
        ->and($result['name'])->toBe('Living Room')
        ->and($result['size'])->toBe('medium')
        ->and($result['surfaces'])->not->toBeEmpty()
        ->and($result['price'])->toBeGreaterThan(0);
});

// =========================================================================
// SURFACE-LEVEL METHODS
// =========================================================================

it('adds surface with auto-calculated quantity from area dimensions', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Test Room',
        'scope_type' => 'interior',
        'length' => 14,
        'width' => 12,
        'height' => 8,
        'wall_count' => 4,
    ]);

    $rate = ProductionRate::factory()->create([
        'name' => 'Smooth Walls',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'unit_type' => 'sqft',
        'rate_type' => 'sqft_hr',
    ]);

    $result = $this->service->addSurfaceToArea(
        areaId: $area->id,
        surfaceCategory: 'walls',
        productionRateId: $rate->id,
        coats: 2,
    );

    expect($result['surface_id'])->toBeGreaterThan(0)
        ->and($result['name'])->toBe('Walls')
        ->and($result['category'])->toBe('walls')
        ->and($result['quantity'])->toBe((float) $area->wall_sqft)
        ->and($result['unit'])->toBe('sqft');
});

it('adds ceiling surface with ceiling sqft', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Test Room',
        'scope_type' => 'interior',
        'length' => 14,
        'width' => 12,
        'height' => 8,
    ]);

    $rate = ProductionRate::factory()->create([
        'name' => 'Ceiling Flat',
        'scope' => 'interior',
        'surface_category' => 'ceiling',
        'production_rate' => 250,
        'unit_type' => 'sqft',
        'rate_type' => 'sqft_hr',
    ]);

    $result = $this->service->addSurfaceToArea(
        areaId: $area->id,
        surfaceCategory: 'ceiling',
        productionRateId: $rate->id,
    );

    expect($result['quantity'])->toBe((float) $area->ceiling_sqft);
});

it('updates a surface production rate', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'scope_type' => 'interior',
    ]);

    $oldRate = ProductionRate::factory()->create([
        'production_rate' => 200,
        'scope' => 'interior',
    ]);

    $surface = EstimateAreaSurface::factory()->forArea($area)->forRate($oldRate)->create();

    $newRate = ProductionRate::factory()->create([
        'name' => 'Textured Walls',
        'production_rate' => 150,
        'scope' => 'interior',
    ]);

    $result = $this->service->updateSurface(
        surfaceId: $surface->id,
        productionRateId: $newRate->id,
    );

    expect($result['production_rate_id'])->toBe($newRate->id);
});

it('removes a surface', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)->create();

    $result = $this->service->removeSurface($surface->id);

    expect($result['success'])->toBeTrue()
        ->and(EstimateAreaSurface::find($surface->id))->toBeNull();
});

// =========================================================================
// AREA MANAGEMENT
// =========================================================================

it('removes an area and all surfaces', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create();
    EstimateAreaSurface::factory()->forArea($area)->count(3)->create();

    $result = $this->service->removeArea($area->id);

    expect($result['success'])->toBeTrue()
        ->and(EstimateArea::find($area->id))->toBeNull();
});

it('updates area dimensions and recalculates surface quantities', function () {
    $area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'length' => 10,
        'width' => 10,
        'height' => 8,
    ]);

    $rate = ProductionRate::factory()->create([
        'production_rate' => 200,
        'scope' => 'interior',
    ]);

    // Add a wall surface
    $this->service->addSurfaceToArea($area->id, 'walls', $rate->id);

    $result = $this->service->updateAreaDimensions(
        areaId: $area->id,
        length: 16,
        width: 14,
    );

    expect($result['wall_sqft'])->toBeGreaterThan(0)
        ->and($result['surfaces_updated'])->toBe(1);
});

// =========================================================================
// ESTIMATE SUMMARY
// =========================================================================

it('gets estimate summary with per-area breakdown', function () {
    $area1 = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Living Room',
        'scope_type' => 'interior',
        'cached_cogs' => 100,
        'cached_customer_price' => 200,
    ]);
    $area2 = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Bedroom',
        'scope_type' => 'interior',
        'cached_cogs' => 80,
        'cached_customer_price' => 160,
    ]);

    $summary = $this->service->getEstimateSummary($this->estimate);

    expect($summary['areas'])->toHaveCount(2)
        ->and($summary['total_price'])->toBeGreaterThan(0);
});
