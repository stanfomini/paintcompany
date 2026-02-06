<?php

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
 * MARGIN-BASED PBP FORMULA:
 * - OTD = COGS / (1 - margin%)
 *
 * Where COGS = Labor + Cleanup + Sundries + Materials + Material Tax + Labor Tax
 *
 * Key Settings (from Inputs Tab):
 * - Wage Rate: $21.00/hr (base wage, NOT multiplied by payroll burden)
 * - Interior Margin: 50% (profit as % of sale price, OTD = COGS × 2)
 * - Exterior Margin: 41% (profit as % of sale price, OTD = COGS / 0.59)
 * - Cleanup ratio: 16 (1 hr cleanup per 16 labor hrs)
 * - Sundries: $3.30/hr
 * - Material Tax Rate: 7.5%
 * - Labor Tax Rate: 0% (most states don't tax labor)
 *
 * IMPORTANT: Payroll burden is NOT applied to the hourly wage.
 * It's factored into determining what the margin% should be.
 * The other margin percentages (profit, overhead, marketing, sales, production_management)
 * are for a SEPARATE business analysis tool, NOT applied to customer estimates.
 *
 * Production Rates:
 * - Wall Painting: 300 sqft/hr
 * - Ceiling Painting: 400 sqft/hr
 * - Trim/Baseboards: 100 lnft/hr
 * - Door Painting: 4 doors/hr (0.25 hr per door = 15 min per door)
 */
beforeEach(function () {
    // Create user with a deal
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->deal = Deal::factory()->create();

    // Set up company settings matching the simplified PBP formula
    $settings = CompanySetting::getInstance();
    $settings->update([
        // Base wage (NOT multiplied by payroll burden for COGS)
        'base_hourly_wage' => 21.00,
        'payroll_burden_multiplier' => 1.35, // For business analysis only, not COGS
        // Margin (ONLY margin applied to customer pricing)
        'markup_percent' => 50, // Legacy field, kept for backwards compatibility
        'margin_percent' => 50,
        // Business analysis margins (NOT applied to customer pricing)
        'net_profit_margin_percent' => 25,
        'overhead_percent' => 19,
        'marketing_percent' => 8,
        'sales_percent' => 7,
        'production_management_percent' => 7,
        // Tax rates
        'material_tax_rate' => 7.5,
        'labor_tax_rate' => 0, // Most states don't tax labor
        // Other settings
        'cleanup_hours_ratio' => 16,
        'sundries_cost_per_prep_hour' => 3.30,
        'recycle_fee_per_gallon' => 0.54,
        // Scope-specific settings
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'interior_markup_percent' => 50, // Legacy
        'exterior_markup_percent' => 41, // Legacy
        'interior_margin_percent' => 50,
        'exterior_margin_percent' => 41,
        'interior_cleanup_hours_ratio' => 16,
        'exterior_cleanup_hours_ratio' => 20,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
    ]);

    // Create production rate category
    $this->category = ProductionRateCategory::firstOrCreate(
        ['name' => 'Interior'],
        ['description' => 'Interior painting rates', 'sort_order' => 1]
    );

    // Create production rates matching spreadsheet
    // Note: hourly rates now come from Company Settings, not ProductionRate
    $this->wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
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
     * Manual calculation for 200 sqft wall, 2 coats using SIMPLIFIED PBP formula:
     *
     * STEP 1: Labor Hours = 200 / 300 × 1.8 = 1.2 hours
     * STEP 2: Labor Cost = 1.2 × $21.00 = $25.20 (base wage, NOT effective rate)
     * STEP 3: Cleanup Hours = 1.2 / 16 = 0.075 hours
     *         Cleanup Cost = 0.075 × $21.00 = $1.575
     * STEP 4: Sundries = 1.2 × $3.30 = $3.96
     * STEP 5: Materials = 200 × $0.03 × 1.8 = $10.80
     * STEP 6: Material Tax = $10.80 × 7.5% = $0.81
     * STEP 7: Labor Tax = ($25.20 + $1.575) × 0% = $0 (not taxed in most states)
     * STEP 8: COGS = $25.20 + $1.575 + $3.96 + $10.80 + $0.81 + $0 = $42.345
     * STEP 9: OTD = COGS × (1 + 50%) = $42.345 × 1.50 = $63.52
     *
     * Note: The only margin applied is the 50% markup. Profit/overhead/etc are NOT applied.
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
        ->test('estimates.template-builder', ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $selectedTemplates = $component->get('selectedTemplates');

    expect($selectedTemplates)->toHaveCount(1);

    $templateData = $selectedTemplates[0];

    // Verify labor hours calculation
    // 200 sqft / 300 sqft/hr × 1.8 (2 coats) = 1.2 hours
    expect($templateData['labor_hours'])->toBeGreaterThan(1.0);
    expect($templateData['labor_hours'])->toBeLessThan(1.5);

    // Verify labor cost
    // 1.2 × $28.35 (effective rate: $21 × 1.35 burden) = $34.02
    expect($templateData['labor_cost'])->toBeGreaterThan(28);
    expect($templateData['labor_cost'])->toBeLessThan(40);

    // Verify cleanup hours (labor / 16)
    expect($templateData['cleanup_hours'])->toBeGreaterThan(0.05);
    expect($templateData['cleanup_hours'])->toBeLessThan(0.2);

    // Verify sundries cost ($3.30 per labor hour)
    expect($templateData['sundries_cost'])->toBeGreaterThan(3);
    expect($templateData['sundries_cost'])->toBeLessThan(6);

    // Verify with_margins is higher than base_total (margin applied)
    expect($templateData['with_margins'])->toBeGreaterThan($templateData['base_total']);

    // Verify margin multiplier is exactly 2.0 (50% margin)
    // Formula: OTD = COGS / (1 - margin%) = COGS / 0.5 = COGS × 2
    $marginMultiplier = $templateData['with_margins'] / $templateData['base_total'];
    expect($marginMultiplier)->toBeGreaterThan(1.99);
    expect($marginMultiplier)->toBeLessThan(2.01);
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
        ->test('estimates.template-builder', ['deal' => $this->deal])
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
        ->test('estimates.template-builder', ['deal' => $this->deal])
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

    $component = Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['deal' => $this->deal]);

    // Get breakdown for 1 coat
    $oneCoatBreakdown = $component->instance()->getTemplateCostBreakdown($template->id, 200, 1);
    $oneCoatLabor = $oneCoatBreakdown['labor_hours'];

    // Get breakdown for 2 coats
    $twoCoatBreakdown = $component->instance()->getTemplateCostBreakdown($template->id, 200, 2);
    $twoCoatLabor = $twoCoatBreakdown['labor_hours'];

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
        ->test('estimates.template-builder', ['deal' => $this->deal])
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
        ->test('estimates.template-builder', ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $templateData = $component->get('selectedTemplates.0');

    // Sundries = labor_hours × $3.30
    $expectedSundries = $templateData['labor_hours'] * 3.30;
    $actualSundries = $templateData['sundries_cost'];

    expect($actualSundries)->toBe(round($expectedSundries, 2));
});

it('applies only margin percentage to COGS for customer pricing', function () {
    /**
     * MARGIN-BASED PBP FORMULA:
     * OTD = COGS / (1 - margin%)
     *
     * For interior 50% margin: OTD = COGS / 0.50 = COGS × 2
     * For exterior 41% margin: OTD = COGS / 0.59 ≈ COGS × 1.695
     *
     * IMPORTANT: Profit, overhead, marketing, sales, production_management percentages
     * are for business analysis only - they are NOT applied to customer pricing.
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
        ->test('estimates.template-builder', ['deal' => $this->deal])
        ->call('addTemplate', $template->id);

    $templateData = $component->get('selectedTemplates.0');

    $cogs = $templateData['base_total']; // base_total IS the COGS
    $withMargins = $templateData['with_margins'];

    // OTD = COGS / (1 - 50%) = COGS / 0.50 = COGS × 2
    $expectedWithMargins = $cogs / (1 - 0.50);

    // The margin multiplier should be exactly 2.0 (50% interior margin)
    $marginMultiplier = $withMargins / $cogs;
    expect($marginMultiplier)->toBeGreaterThan(1.99);
    expect($marginMultiplier)->toBeLessThan(2.01);

    // Verify the with_margins matches the expected calculation
    expect($withMargins)->toBe(round($expectedWithMargins, 2));
});

it('calculates material costs from paint product when set at estimate level', function () {
    /**
     * When a paint product is selected, material cost should be calculated from:
     * - Paint coverage (sqft/gallon)
     * - Price per gallon
     * - Recycle fee per gallon
     *
     * Formula: gallons = sqft × coats / coverage, rounded up
     * Material cost = gallons × price_per_gallon + gallons × recycle_fee
     */
    $paintProduct = \App\Models\PaintProduct::factory()->create([
        'name' => 'Test Paint',
        'price_per_gallon' => 50.00,
        'coverage_sqft_brush_roll' => 400, // 400 sqft per gallon
        'coverage_sqft_spray' => 350,
    ]);

    $settings = CompanySetting::getInstance();
    $settings->update(['recycle_fee_per_gallon' => 0.54]);

    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Paint Cost Test Room',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 400, // 400 sqft
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
        'default_surfaces' => ['walls'], // Required for surfaces to be created
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['deal' => $this->deal])
        ->set('title', 'Paint Cost Test Estimate')
        ->set('defaultPaintProductId', $paintProduct->id)
        ->call('addTemplate', $template->id)
        ->call('saveAsDraft');

    // Verify the estimate was created with the paint product
    $estimate = \App\Models\Estimate::where('title', 'Paint Cost Test Estimate')->first();
    expect($estimate)->not->toBeNull();
    expect($estimate->default_paint_product_id)->toBe($paintProduct->id);

    // Get the surface and check material costs
    $area = $estimate->areas()->first();
    expect($area)->not->toBeNull();
    expect($area->default_paint_product_id)->toBe($paintProduct->id);

    $surface = $area->surfaces()->first();
    expect($surface)->not->toBeNull();

    // Verify paint product is saved
    expect($surface->paint_product_id)->toBe($paintProduct->id);

    // Verify paint calculations are populated
    // Note: wall_sqft is calculated from dimensions, not template's default_quantity
    // Template sqft 400 → dimensions √400 ≈ 20×20×9 → wall_sqft = perimeter×height = 80×9 = 720
    expect((int) $surface->paint_gallons)->toBeGreaterThan(0);
    expect((float) $surface->paint_cost)->toBeGreaterThan(0);

    // Material cost should include paint cost + recycle fee
    expect((float) $surface->material_cost)->toBeGreaterThan((float) $surface->paint_cost);

    // Verify the formula: gallons × price + gallons × recycle_fee
    $expectedMaterialCost = (int) $surface->paint_gallons * 50.00 + (int) $surface->paint_gallons * 0.54;
    expect((float) $surface->material_cost)->toBe($expectedMaterialCost);
});

it('saves paint product data to surfaces when saving estimate', function () {
    /**
     * Verify that paint_product_id, paint_gallons, paint_cost are all saved to EstimateAreaSurface
     */
    $paintProduct = \App\Models\PaintProduct::factory()->create([
        'name' => 'Sherwin-Williams Duration',
        'price_per_gallon' => 75.00,
        'coverage_sqft_brush_roll' => 350,
    ]);

    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'Database Save Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 350, // Exactly 1 gallon coverage at 350 sqft/gal
        'default_unit' => 'sqft',
        'default_coats' => 1,
        'scope_type' => 'interior',
        'is_active' => true,
        'default_surfaces' => ['walls'], // Required for surfaces to be created
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['deal' => $this->deal])
        ->set('title', 'Paint Data Save Test')
        ->set('defaultPaintProductId', $paintProduct->id)
        ->call('addTemplate', $template->id)
        ->call('saveAsDraft');

    $estimate = \App\Models\Estimate::where('title', 'Paint Data Save Test')->first();
    expect($estimate)->not->toBeNull();

    $surface = $estimate->areas()->first()->surfaces()->first();

    // All paint data should be saved
    expect($surface->paint_product_id)->toBe($paintProduct->id);
    expect((int) $surface->paint_gallons)->toBeGreaterThan(0);
    expect((float) $surface->paint_cost)->toBeGreaterThan(0);
    expect((float) $surface->material_cost)->toBeGreaterThan(0);

    // Paint cost should be gallons × price_per_gallon
    $expectedPaintCost = (int) $surface->paint_gallons * 75.00;
    expect((float) $surface->paint_cost)->toBe($expectedPaintCost);

    // Verify the relationship works
    expect($surface->paintProduct)->not->toBeNull();
    expect($surface->paintProduct->name)->toBe('Sherwin-Williams Duration');
});

it('has zero material cost when no paint product selected', function () {
    /**
     * Without a paint product, material cost is 0.
     * material_cost_per_unit was removed from ProductionRate - material costs
     * now come exclusively from PaintProduct selection.
     */
    $template = EstimateTemplate::factory()->create([
        'template_category_id' => $this->templateCategory->id,
        'name' => 'No Material Test',
        'production_rate_id' => $this->wallRate->id,
        'pricing_method' => 'production_rate',
        'default_quantity' => 300,
        'default_unit' => 'sqft',
        'default_coats' => 2,
        'scope_type' => 'interior',
        'is_active' => true,
        'default_surfaces' => ['walls'], // Required for surfaces to be created
    ]);

    Livewire::actingAs($this->user)
        ->test('estimates.template-builder', ['deal' => $this->deal])
        ->set('title', 'No Material Test')
        // No paint product set
        ->call('addTemplate', $template->id)
        ->call('saveAsDraft');

    $estimate = \App\Models\Estimate::where('title', 'No Material Test')->first();
    expect($estimate)->not->toBeNull();
    expect($estimate->default_paint_product_id)->toBeNull();

    $area = $estimate->areas()->first();
    $surface = $area->surfaces()->first();

    // No paint product data
    expect($surface->paint_product_id)->toBeNull();
    expect($surface->paint_gallons)->toBeNull();
    expect($surface->paint_cost)->toBeNull();

    // Material cost is 0 without paint product
    // material_cost_per_unit was removed from ProductionRate
    expect((float) $surface->material_cost)->toBe(0.0);
    expect((float) $surface->material_cost_per_unit)->toBe(0.0);
});
