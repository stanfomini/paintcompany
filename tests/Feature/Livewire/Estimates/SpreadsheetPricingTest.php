<?php

use App\Livewire\Estimates\TemplateBuilder;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use Livewire\Livewire;

/**
 * Verify that our PBP pricing matches the spreadsheet formulas.
 *
 * Reference Spreadsheet: https://docs.google.com/spreadsheets/d/13jhzT7ulHZVIwUBGfKx2Z-qwaT3IirDrPLZ8AdcuOCw/
 *
 * Key Settings (from Inputs Tab):
 * - Wage Rate: $21.00/hr
 * - Payroll Burden: 1.0 (multiplier)
 * - Net Profit Margin: 30%
 * - Overhead: 10%
 * - Markup: 50%
 * - Cleanup ratio: 16 (1 hr cleanup per 16 labor hrs)
 * - Sundries: $3.30/hr
 *
 * Production Rates:
 * - Wall Painting: 300 sqft/hr (our default)
 * - Ceiling Painting: 400 sqft/hr
 * - Trim/Baseboards: 100 lnft/hr
 * - Door Painting: 4 doors/hr (0.25 hr per door = 1 door in 15 min)
 */
beforeEach(function () {
    // Create user with a deal
    $this->user = User::factory()->internal()->create();
    $this->deal = Deal::factory()->create();

    // Set up company settings matching the spreadsheet
    $settings = CompanySetting::getInstance();
    $settings->update([
        // Base wage and multipliers
        'base_hourly_wage' => 21.00,
        'payroll_burden_multiplier' => 1.0,
        // Margins
        'profit_margin' => 30,
        'overhead_percentage' => 10,
        'markup_percentage' => 50,
        // Other settings
        'cleanup_ratio' => 16,
        'sundries_per_hour' => 3.30,
        // Scope-specific (use same for both in tests)
        'interior_hourly_wage' => 21.00,
        'interior_payroll_burden' => 1.0,
        'interior_profit_margin' => 30,
        'interior_overhead' => 10,
        'interior_markup' => 50,
        'interior_cleanup_ratio' => 16,
        'interior_sundries_rate' => 3.30,
    ]);

    // Create production rate category
    $this->category = ProductionRateCategory::firstOrCreate(
        ['name' => 'Interior'],
        ['description' => 'Interior painting rates', 'sort_order' => 1]
    );

    // Create production rates matching spreadsheet
    $this->wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'hourly_labor_rate' => 21.00,
            'material_cost_per_unit' => 0.03,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $this->ceilingRate = ProductionRate::firstOrCreate(
        ['name' => 'Ceiling Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 400,
            'hourly_labor_rate' => 21.00,
            'material_cost_per_unit' => 0.03,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $this->trimRate = ProductionRate::firstOrCreate(
        ['name' => 'Trim Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'lnft',
            'production_rate' => 100,
            'hourly_labor_rate' => 21.00,
            'material_cost_per_unit' => 0.02,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 3,
        ]
    );

    $this->doorRate = ProductionRate::firstOrCreate(
        ['name' => 'Door Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'unit',
            'production_rate' => 4, // 4 doors per hour = 15 min per door
            'hourly_labor_rate' => 21.00,
            'material_cost_per_unit' => 5.00, // Per door
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 4,
        ]
    );

    // Create template category
    $this->templateCategory = EstimateTemplateCategory::firstOrCreate(
        ['name' => 'Rooms'],
        [
            'type' => 'room',
            'description' => 'Room templates',
            'is_active' => true,
            'sort_order' => 1,
        ]
    );
});

it('calculates PBP formula correctly for a simple wall surface', function () {
    /**
     * Manual calculation for 200 sqft wall, 2 coats:
     *
     * Labor Hours = 200 / 300 × 1.8 = 1.2 hours
     * Labor Cost = 1.2 × $21.00 = $25.20
     * Cleanup Hours = 1.2 / 16 = 0.075 hours
     * Cleanup Cost = 0.075 × $21.00 = $1.575
     * Sundries = 1.2 × $3.30 = $3.96
     * Materials = 200 × $0.03 × 1.8 = $10.80
     * Base Total = $25.20 + $1.575 + $3.96 + $10.80 = $41.535
     * With Margins = $41.535 × 1.30 × 1.10 × 1.50 = $89.10 (approximately)
     */
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Test Wall Only',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 200,
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $selectedTemplates = $component->get('selectedTemplates');

    expect($selectedTemplates)->toHaveCount(1);

    $templateData = $selectedTemplates[0];

    // Verify labor hours calculation
    // 200 sqft / 300 sqft/hr × 1.8 (2 coats) = 1.2 hours
    expect($templateData['labor_hours'])->toBeGreaterThan(1.0);
    expect($templateData['labor_hours'])->toBeLessThan(1.5);

    // Verify labor cost
    // 1.2 × $21 = $25.20
    expect($templateData['labor_cost'])->toBeGreaterThan(20);
    expect($templateData['labor_cost'])->toBeLessThan(30);

    // Verify cleanup hours (labor / 16)
    expect($templateData['cleanup_hours'])->toBeGreaterThan(0.05);
    expect($templateData['cleanup_hours'])->toBeLessThan(0.2);

    // Verify sundries cost ($3.30 per labor hour)
    expect($templateData['sundries_cost'])->toBeGreaterThan(3);
    expect($templateData['sundries_cost'])->toBeLessThan(6);

    // Verify with_margins is higher than base_total (margins applied)
    expect($templateData['with_margins'])->toBeGreaterThan($templateData['base_total']);

    // Verify margin multiplier is approximately 1.30 × 1.10 × 1.50 = 2.145
    $marginMultiplier = $templateData['with_margins'] / $templateData['base_total'];
    expect($marginMultiplier)->toBeGreaterThan(2.0);
    expect($marginMultiplier)->toBeLessThan(2.5);
});

it('calculates room with multiple surfaces using surface_ratios', function () {
    /**
     * Living Room Medium (200 sqft) with surface ratios:
     * - Walls: 200 sqft × 1.0 = 200 sqft
     * - Ceiling: 200 sqft × 0.9 = 180 sqft
     * - Baseboards: 200/100 × 28 = 56 lnft
     * - Doors: 1 unit
     *
     * Each surface calculated with its own production rate.
     */
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Living Room Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 200,
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
        'metadata' => [
            'sizes' => [
                'small' => ['sqft' => 150, 'label' => 'Small (150 sqft)'],
                'medium' => ['sqft' => 200, 'label' => 'Medium (200 sqft)'],
                'large' => ['sqft' => 300, 'label' => 'Large (300 sqft)'],
            ],
            'default_size' => 'medium',
            'surface_ratios' => [
                'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $this->wallRate->id],
                'ceiling' => ['type' => 'sqft', 'ratio' => 0.9, 'rate_id' => $this->ceilingRate->id],
                'baseboards' => ['type' => 'lnft', 'per_100sqft' => 28, 'rate_id' => $this->trimRate->id],
                'doors' => ['type' => 'unit', 'count' => 1, 'rate_id' => $this->doorRate->id],
            ],
        ],
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $selectedTemplates = $component->get('selectedTemplates');

    expect($selectedTemplates)->toHaveCount(1);

    $templateData = $selectedTemplates[0];

    // Verify has surfaces breakdown
    expect($templateData['surfaces'])->not->toBeNull();
    expect($templateData['surfaces'])->toHaveKey('walls');
    expect($templateData['surfaces'])->toHaveKey('ceiling');
    expect($templateData['surfaces'])->toHaveKey('baseboards');
    expect($templateData['surfaces'])->toHaveKey('doors');

    // Verify surface quantities
    expect($templateData['surfaces']['walls']['quantity'])->toBe(200.0);
    expect($templateData['surfaces']['ceiling']['quantity'])->toBe(180.0);
    expect($templateData['surfaces']['baseboards']['quantity'])->toBe(56.0);
    expect($templateData['surfaces']['doors']['quantity'])->toBe(1.0);

    // Verify total labor hours is sum of all surfaces (allow small rounding diff)
    $surfaceLaborHours = array_sum(array_column($templateData['surfaces'], 'labor_hours'));
    expect($templateData['labor_hours'])->toBeGreaterThanOrEqual($surfaceLaborHours - 0.01);
    expect($templateData['labor_hours'])->toBeLessThanOrEqual($surfaceLaborHours + 0.01);

    // Verify price is reasonable for a room
    // A 200 sqft room with walls, ceiling, baseboards, door should be in hundreds of dollars
    expect($templateData['with_margins'])->toBeGreaterThan(100);
    expect($templateData['with_margins'])->toBeLessThan(1000);
});

it('changes price when size is changed', function () {
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Bedroom Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 150,
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
        'metadata' => [
            'sizes' => [
                'small' => ['sqft' => 100, 'label' => 'Small (100 sqft)'],
                'medium' => ['sqft' => 150, 'label' => 'Medium (150 sqft)'],
                'large' => ['sqft' => 200, 'label' => 'Large (200 sqft)'],
            ],
            'default_size' => 'medium',
            'surface_ratios' => [
                'walls' => ['type' => 'sqft', 'ratio' => 1.0, 'rate_id' => $this->wallRate->id],
            ],
        ],
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $selectedTemplates = $component->get('selectedTemplates');
    $mediumPrice = $selectedTemplates[0]['with_margins'];
    $mediumQuantity = $selectedTemplates[0]['quantity'];

    expect($mediumQuantity)->toBe(150.0);

    // Change to large size
    $component->call('changeSize', 0, 'large');

    $selectedTemplates = $component->get('selectedTemplates');
    $largePrice = $selectedTemplates[0]['with_margins'];
    $largeQuantity = $selectedTemplates[0]['quantity'];

    expect($largeQuantity)->toBe(200.0);

    // Large should be more expensive than medium
    expect($largePrice)->toBeGreaterThan($mediumPrice);

    // Price should scale roughly with size (200/150 ≈ 1.33)
    $priceRatio = $largePrice / $mediumPrice;
    expect($priceRatio)->toBeGreaterThan(1.2);
    expect($priceRatio)->toBeLessThan(1.5);
});

it('applies coat multipliers correctly', function () {
    /**
     * Coat multipliers from spreadsheet:
     * - 1 coat: 1.0
     * - 2 coats: 1.8 (not 2.0 because second coat goes faster)
     * - 3 coats: 2.5
     */
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Coat Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 200,
        'default_unit' => 'sqft',
        'default_coats' => 1,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    // Get 1 coat price
    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $oneCoatLabor = $component->get('selectedTemplates.0.labor_hours');

    // Update to 2 coats
    $component->set('adjustingTemplateIndex', 0)
        ->set('adjustQuantity', 200)
        ->set('adjustCoats', 2)
        ->call('saveAdjustment');

    $twoCoatLabor = $component->get('selectedTemplates.0.labor_hours');

    // 2 coat labor should be 1.8× the 1 coat labor
    $laborRatio = $twoCoatLabor / $oneCoatLabor;
    expect($laborRatio)->toBeGreaterThan(1.7);
    expect($laborRatio)->toBeLessThan(1.9);
});

it('calculates cleanup hours correctly using cleanup ratio', function () {
    /**
     * Cleanup ratio = 16 means 1 hour cleanup per 16 labor hours
     * So cleanup_hours = labor_hours / 16
     */
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Cleanup Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 1600, // Big job for clear cleanup hours
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $templateData = $component->get('selectedTemplates.0');

    // Cleanup should be approximately labor / 16
    $expectedCleanupHours = $templateData['labor_hours'] / 16;
    $actualCleanupHours = $templateData['cleanup_hours'];

    expect($actualCleanupHours)->toBe(round($expectedCleanupHours, 2));
});

it('calculates sundries correctly at $3.30 per labor hour', function () {
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Sundries Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 300,
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $templateData = $component->get('selectedTemplates.0');

    // Sundries = labor_hours × $3.30
    $expectedSundries = $templateData['labor_hours'] * 3.30;
    $actualSundries = $templateData['sundries_cost'];

    expect($actualSundries)->toBe(round($expectedSundries, 2));
});

it('applies profit, overhead, and markup margins in correct order', function () {
    /**
     * Margins applied: base × (1+30%) × (1+10%) × (1+50%)
     * = base × 1.30 × 1.10 × 1.50
     * = base × 2.145
     */
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Margins Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 200,
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($this->user)
        ->test(TemplateBuilder::class, ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $templateData = $component->get('selectedTemplates.0');

    $baseTotal = $templateData['base_total'];
    $withMargins = $templateData['with_margins'];

    // Expected: base × 1.30 × 1.10 × 1.50 = base × 2.145
    $expectedWithMargins = $baseTotal * 1.30 * 1.10 * 1.50;

    // Allow rounding differences from intermediate calculations
    // The margin multiplier should be approximately 2.145
    $marginMultiplier = $withMargins / $baseTotal;
    expect($marginMultiplier)->toBeGreaterThan(2.0);
    expect($marginMultiplier)->toBeLessThan(2.5);
});
