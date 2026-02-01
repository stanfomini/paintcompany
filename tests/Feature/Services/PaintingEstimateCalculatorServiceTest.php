<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\NestedAreaTemplate;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use App\Services\PaintingEstimateCalculatorService;

beforeEach(function () {
    // Ensure company settings are configured
    CompanySetting::updateOrCreate(
        ['id' => 1],
        [
            'base_hourly_wage' => 25.00,
            'payroll_burden_multiplier' => 1.35,
            'net_profit_margin_percent' => 30.00,
            'overhead_percent' => 12.00,
            'markup_percent' => 45.00,
            'recycle_fee_per_gallon' => 0.54,
        ]
    );
});

it('calculates quick estimate without substrates', function () {
    $service = new PaintingEstimateCalculatorService;

    $result = $service->quickEstimate(
        sqft: 400,
        productionRate: 200,
        coats: 2
    );

    // 400 sqft / 200 rate = 2 hours base
    // 2 hours × 1.8 coat multiplier = 3.6 hours
    expect($result['base_hours'])->toBe(2.0);
    expect($result['hours'])->toBe(3.6);
    expect($result['sqft'])->toBe(400.0);
    expect($result['coats'])->toBe(2);
    expect($result['labor_cost'])->toBeGreaterThan(0);
    expect($result['price_with_margins'])->toBeGreaterThan($result['labor_cost']);
});

it('applies substrate multipliers in quick estimate', function () {
    $substrate = Substrate::factory()->peelingPaint()->create();

    $service = new PaintingEstimateCalculatorService;

    $resultWithoutSubstrate = $service->quickEstimate(400, 200, 2);
    $resultWithSubstrate = $service->quickEstimate(400, 200, 2, $substrate->id);

    // Peeling paint has higher multipliers (2.0 prep, 1.1 paint)
    expect($resultWithSubstrate['hours'])->toBeGreaterThan($resultWithoutSubstrate['hours']);
    expect($resultWithSubstrate['multipliers']['prep'])->toBe(2.0);
    expect($resultWithSubstrate['multipliers']['paint'])->toBe(1.1);
});

it('calculates paint cost for surface', function () {
    $brand = PaintBrand::factory()->sherwinWilliams()->create();
    $product = PaintProduct::factory()
        ->for($brand, 'brand')
        ->proMar200()
        ->create();

    $service = new PaintingEstimateCalculatorService;

    $result = $service->calculatePaintForSurface(
        sqft: 400,
        paintProductId: $product->id,
        coats: 2,
        applicationMethod: 'brush_roll'
    );

    expect($result)->not->toBeNull();
    expect($result['gallons'])->toBeGreaterThan(0);
    expect($result['gallons_rounded'])->toBeGreaterThan(0);
    expect($result['paint_cost'])->toBeGreaterThan(0);
    expect($result['recycle_fee'])->toBeGreaterThan(0);
    expect($result['total_cost'])->toBe(
        round($result['paint_cost'] + $result['recycle_fee'], 2)
    );
});

it('creates area data from nested template', function () {
    $template = NestedAreaTemplate::factory()->smallCloset()->create();
    $estimate = Estimate::factory()->create();

    $service = new PaintingEstimateCalculatorService;

    $data = $service->createAreaFromTemplate($template, $estimate->id);

    expect($data['estimate_id'])->toBe($estimate->id);
    expect($data['nested_area_template_id'])->toBe($template->id);
    expect($data['name'])->toBe('Small Closet');
    expect($data['is_fixed_price'])->toBeTrue();
    expect($data['fixed_price_amount'])->toBe($template->fixed_price);
});

it('calculates fixed price area correctly', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'name' => 'Small Closet',
        'is_fixed_price' => true,
        'fixed_price_amount' => 95.00,
    ]);

    $service = new PaintingEstimateCalculatorService;
    $result = $service->calculateArea($area);

    expect($result['is_fixed_price'])->toBeTrue();
    expect($result['total'])->toBe(95.00);
    expect($result['labor']['hours'])->toBe(0);
    expect($result['materials']['total'])->toBe(0);
});

it('provides pricing explanation with current settings', function () {
    $service = new PaintingEstimateCalculatorService;
    $explanation = $service->getPricingExplanation();

    expect($explanation)->toHaveKey('formula');
    expect($explanation)->toHaveKey('steps');
    expect($explanation)->toHaveKey('settings');
    expect($explanation['settings']['base_hourly_wage'])->toBe(25.00);
    expect($explanation['settings']['profit_percent'])->toBe(30.00);
    expect($explanation['settings']['overhead_percent'])->toBe(12.00);
    expect($explanation['settings']['markup_percent'])->toBe(45.00);
});

it('calculates correct effective hourly rate', function () {
    $service = new PaintingEstimateCalculatorService;
    $explanation = $service->getPricingExplanation();

    // $25 × 1.35 = $33.75
    expect($explanation['settings']['effective_hourly_rate'])->toBe(33.75);
});

it('applies all margins correctly', function () {
    $settings = CompanySetting::getInstance();

    // Test with $100 base cost
    // Profit: 100 × 1.30 = 130
    // Overhead: 130 × 1.12 = 145.60
    // Markup: 145.60 × 1.45 = 211.12
    $result = $settings->calculateWithMargins(100.00);

    expect($result)->toBe(211.12);
});

it('calculates labor hours with coat multipliers', function () {
    $category = ProductionRateCategory::factory()->create(['type' => 'interior']);
    $rate = ProductionRate::factory()->create([
        'category_id' => $category->id,
        'production_rate' => 200,
        'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
    ]);

    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'length' => 20,
        'width' => 10,
        'height' => 8,
    ]);

    EstimateAreaSurface::factory()->create([
        'area_id' => $area->id,
        'production_rate_id' => $rate->id,
        'surface_type' => 'walls',
        'quantity' => 400, // wall sqft
        'coats' => 2,
        'is_included' => true,
    ]);

    $service = new PaintingEstimateCalculatorService;
    $labor = $service->calculateLabor($area);

    // 400 / 200 = 2 base hours
    // 2 × 1.8 (coat multiplier) = 3.6 hours
    expect($labor['hours'])->toBe(3.6);
    expect($labor['breakdown'])->toHaveCount(1);
    expect($labor['breakdown'][0]['base_hours'])->toBe(2.0);
    expect($labor['breakdown'][0]['coat_multiplier'])->toBe(1.8);
});

it('calculates nested areas recursively', function () {
    $estimate = Estimate::factory()->create();
    $parentArea = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'name' => 'Master Bedroom',
    ]);

    $closet1 = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'parent_area_id' => $parentArea->id,
        'name' => 'Small Closet',
        'is_fixed_price' => true,
        'fixed_price_amount' => 95.00,
    ]);

    $closet2 = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'parent_area_id' => $parentArea->id,
        'name' => 'Large Closet',
        'is_fixed_price' => true,
        'fixed_price_amount' => 245.00,
    ]);

    $service = new PaintingEstimateCalculatorService;
    $nested = $service->calculateNestedAreas($parentArea);

    expect($nested['count'])->toBe(2);
    expect($nested['total'])->toBe(340.00); // 95 + 245
});

it('calculates complete estimate across multiple areas', function () {
    $estimate = Estimate::factory()->create();

    $area1 = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'name' => 'Living Room',
        'is_fixed_price' => true,
        'fixed_price_amount' => 500.00,
    ]);

    $area2 = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'name' => 'Bedroom',
        'is_fixed_price' => true,
        'fixed_price_amount' => 350.00,
    ]);

    $service = new PaintingEstimateCalculatorService;
    $result = $service->calculateEstimate([$area1, $area2]);

    expect($result['areas'])->toHaveCount(2);
    expect($result['summary']['total'])->toBe(850.00);
    expect($result['pricing'])->toHaveKey('profit_percent');
    expect($result['pricing'])->toHaveKey('overhead_percent');
    expect($result['pricing'])->toHaveKey('markup_percent');
});
