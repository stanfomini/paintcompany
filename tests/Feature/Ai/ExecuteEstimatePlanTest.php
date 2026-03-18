<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Services\EstimateAiService;

beforeEach(function () {
    $this->service = new EstimateAiService;
    $this->estimate = Estimate::factory()->create([
        'status' => 'draft',
        'project_type' => 'interior',
    ]);

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
    CompanySetting::clearInstanceCache();

    // Create interior production rate category
    $this->rateCategory = ProductionRateCategory::factory()->create([
        'name' => 'Interior Painting',
        'type' => 'interior',
    ]);

    // Create standard production rates
    $this->wallRate = ProductionRate::factory()->interior()->create([
        'category_id' => $this->rateCategory->id,
        'name' => 'Interior Walls - Brush/Roll',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'rate_type' => 'sqft_hr',
        'unit_type' => 'sqft',
    ]);
    $this->ceilingRate = ProductionRate::factory()->interior()->create([
        'category_id' => $this->rateCategory->id,
        'name' => 'Interior Ceilings',
        'scope' => 'interior',
        'surface_category' => 'ceiling',
        'production_rate' => 250,
        'rate_type' => 'sqft_hr',
        'unit_type' => 'sqft',
    ]);
    $this->baseboardRate = ProductionRate::factory()->interior()->create([
        'category_id' => $this->rateCategory->id,
        'name' => 'Baseboards',
        'scope' => 'interior',
        'surface_category' => 'baseboard',
        'production_rate' => 60,
        'rate_type' => 'lnft_hr',
        'unit_type' => 'lnft',
    ]);
    $this->doorRate = ProductionRate::factory()->interior()->create([
        'category_id' => $this->rateCategory->id,
        'name' => 'Interior Doors',
        'scope' => 'interior',
        'surface_category' => 'doors',
        'production_rate' => 0.75,
        'rate_type' => 'hours_per_unit',
        'unit_type' => 'unit',
    ]);
});

// =========================================================================
// SINGLE CUSTOM AREA
// =========================================================================

it('executes plan with a single custom area', function () {
    $plan = [
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Living Room',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 14, 'width' => 12, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                    ['name' => 'Baseboards', 'surface_category' => 'baseboard', 'production_rate_id' => $this->baseboardRate->id, 'coats' => 2],
                    ['name' => 'Door', 'surface_category' => 'doors', 'production_rate_id' => $this->doorRate->id, 'coats' => 2, 'quantity_override' => 1],
                ],
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(1)
        ->and($result['surfaces_created'])->toBe(4)
        ->and($result['errors'])->toBeEmpty()
        ->and($result['area_results'][0]['name'])->toBe('Living Room')
        ->and($result['area_results'][0]['price'])->toBeGreaterThan(0)
        ->and($result['estimate_total'])->toBeGreaterThan(0);

    // Verify areas exist in DB
    $areas = $this->estimate->fresh()->areas;
    expect($areas)->toHaveCount(1);
    expect($areas->first()->surfaces)->toHaveCount(4);
});

// =========================================================================
// MULTI-AREA PLAN
// =========================================================================

it('executes plan with multiple custom areas', function () {
    $plan = [
        'estimate_settings' => [
            'title' => '3-Bedroom House Interior',
            'project_type' => 'interior',
        ],
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Bedroom 1',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 12, 'width' => 10, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ],
            ],
            [
                'source' => 'custom',
                'name' => 'Bedroom 2',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 11, 'width' => 10, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ],
            ],
            [
                'source' => 'custom',
                'name' => 'Bedroom 3',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 10, 'width' => 10, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ],
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(3)
        ->and($result['surfaces_created'])->toBe(6)
        ->and($result['errors'])->toBeEmpty();

    // Verify estimate title was updated
    expect($this->estimate->fresh()->title)->toBe('3-Bedroom House Interior');

    // Verify all areas exist
    $areas = $this->estimate->fresh()->areas;
    expect($areas)->toHaveCount(3);
    expect($areas->pluck('name')->toArray())->toContain('Bedroom 1', 'Bedroom 2', 'Bedroom 3');
});

// =========================================================================
// TEMPLATE-BASED AREAS
// =========================================================================

it('executes plan with template-based areas', function () {
    $templateCategory = EstimateTemplateCategory::factory()->create(['name' => 'Interior']);
    $template = EstimateTemplate::factory()
        ->configurable()
        ->interior()
        ->forCategory($templateCategory)
        ->create([
            'name' => 'Bedroom',
            'metadata' => [
                'dimensions' => [
                    'small' => ['length' => 10, 'width' => 10, 'height' => 8, 'sqft' => 100],
                    'medium' => ['length' => 12, 'width' => 12, 'height' => 9, 'sqft' => 144],
                    'large' => ['length' => 14, 'width' => 14, 'height' => 9, 'sqft' => 196],
                ],
                'surface_ratios' => [
                    'walls' => ['ratio' => 1.0, 'rate_id' => $this->wallRate->id],
                    'ceiling' => ['ratio' => 0.9, 'rate_id' => $this->ceilingRate->id],
                    'baseboards' => ['ratio' => 0.28, 'rate_id' => $this->baseboardRate->id],
                ],
            ],
        ]);

    $plan = [
        'areas' => [
            ['source' => 'template', 'template_id' => $template->id, 'size' => 'medium', 'name' => 'Master Bedroom'],
            ['source' => 'template', 'template_id' => $template->id, 'size' => 'small', 'name' => 'Guest Bedroom'],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(2)
        ->and($result['surfaces_created'])->toBeGreaterThanOrEqual(4) // At least 2 surfaces per area from ratios
        ->and($result['errors'])->toBeEmpty();

    // Verify names were overridden
    $areas = $this->estimate->fresh()->areas;
    expect($areas->pluck('name')->toArray())->toContain('Master Bedroom', 'Guest Bedroom');
});

// =========================================================================
// TIER PRICING
// =========================================================================

it('executes plan with tier pricing', function () {
    $brand = \App\Models\PaintBrand::factory()->create();
    $goodPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'ProMar 200',
        'scope_type' => 'interior',
        'quality_tier' => 'good',
        'price_per_gallon' => 42.99,
    ]);
    $betterPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'SuperPaint',
        'scope_type' => 'interior',
        'quality_tier' => 'better',
        'price_per_gallon' => 64.99,
    ]);
    $bestPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Duration',
        'scope_type' => 'interior',
        'quality_tier' => 'best',
        'price_per_gallon' => 79.99,
    ]);

    $plan = [
        'tier_config' => [
            'good' => ['paint_product_id' => $goodPaint->id, 'default_coats' => 1],
            'better' => ['paint_product_id' => $betterPaint->id, 'default_coats' => 2],
            'best' => ['paint_product_id' => $bestPaint->id, 'default_coats' => 2],
        ],
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Living Room',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 14, 'width' => 12, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ],
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(1)
        ->and($result['surfaces_created'])->toBe(2)
        ->and($result['tier_overrides_set'])->toBe(6) // 2 surfaces × 3 tiers
        ->and($result['errors'])->toBeEmpty();

    // Verify tier config was set on estimate
    $estimate = $this->estimate->fresh();
    expect($estimate->paint_tiers)->not->toBeNull();
    expect($estimate->paint_tiers)->toHaveKeys(['good', 'better', 'best']);

    // Verify tier overrides exist on surfaces
    $area = $estimate->areas->first();
    $surface = $area->surfaces->first();
    expect($surface->tier_overrides)->not->toBeNull();
    expect($surface->tier_overrides)->toHaveKeys(['good', 'better', 'best', '_base']);
});

// =========================================================================
// NEW RATE CREATION WITH REF
// =========================================================================

it('executes plan with new production rate references', function () {
    $plan = [
        'create_first' => [
            'production_rates' => [
                [
                    'ref' => 'cabinet_rate',
                    'name' => 'Cabinet Door Faces',
                    'scope' => 'interior',
                    'surface_category' => 'trim',
                    'production_rate' => 0.75,
                    'rate_type' => 'hours_per_unit',
                    'unit_type' => 'unit',
                ],
            ],
        ],
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Kitchen',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 12, 'width' => 14, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Cabinet Faces', 'surface_category' => 'trim', 'production_rate_id' => 'ref:cabinet_rate', 'quantity_override' => 14, 'coats' => 2],
                ],
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(1)
        ->and($result['surfaces_created'])->toBe(2)
        ->and($result['errors'])->toBeEmpty();

    // Verify the production rate was created
    $rate = ProductionRate::where('name', 'Cabinet Door Faces')->first();
    expect($rate)->not->toBeNull()
        ->and((float) $rate->production_rate)->toBe(0.75)
        ->and($rate->rate_type)->toBe('hours_per_unit');

    // Verify the surface references the new rate
    $area = $this->estimate->fresh()->areas->first();
    $cabinetSurface = $area->surfaces->firstWhere('name', 'Cabinet Faces');
    expect($cabinetSurface)->not->toBeNull()
        ->and($cabinetSurface->production_rate_id)->toBe($rate->id)
        ->and((float) $cabinetSurface->quantity)->toBe(14.0);
});

// =========================================================================
// ADDONS AND LINE ITEMS
// =========================================================================

it('executes plan with addons and line items', function () {
    $closet = NestedAreaTemplate::factory()->smallCloset()->create();

    $plan = [
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Master Bedroom',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 14, 'width' => 14, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ],
                'addons' => [
                    ['nested_template_id' => $closet->id, 'quantity' => 2],
                ],
            ],
        ],
        'line_items' => [
            ['description' => 'Furniture moving', 'quantity' => 1, 'unit_price' => 250],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(1)
        ->and($result['errors'])->toBeEmpty();

    // Verify addons
    $area = $this->estimate->fresh()->areas->first();
    expect($area->addons)->toHaveCount(1);
    expect($area->addons->first()->quantity)->toBe(2);

    // Verify line items
    $items = $this->estimate->fresh()->items;
    expect($items)->toHaveCount(1);
    expect($items->first()->description)->toBe('Furniture moving');
});

// =========================================================================
// TRANSACTION ROLLBACK
// =========================================================================

it('reports errors for failed areas without crashing the plan', function () {
    $plan = [
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Valid Area',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 12, 'width' => 10, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ],
            ],
            [
                'source' => 'template',
                'template_id' => 999999, // Non-existent template
                'size' => 'medium',
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    // The plan completes but reports errors for the failed area
    expect($result['success'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty()
        ->and($result['areas_created'])->toBe(2); // Both attempted

    // Valid area was created, failed area has errors
    expect($result['area_results'][0]['name'])->toBe('Valid Area')
        ->and($result['area_results'][1]['errors'])->not->toBeEmpty();
});

// =========================================================================
// EMPTY PLAN
// =========================================================================

it('handles empty plan gracefully', function () {
    $result = $this->service->executePlan($this->estimate, []);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(0)
        ->and($result['surfaces_created'])->toBe(0)
        ->and($result['errors'])->toBeEmpty();
});

// =========================================================================
// CATEGORY VALIDATION (Phase 3)
// =========================================================================

it('allows custom snake_case surface categories for production rates', function () {
    $result = $this->service->createProductionRate(
        name: 'Epoxy Floor Coating',
        scope: 'interior',
        surfaceCategory: 'epoxy_floor',
        productionRate: 100,
        rateType: 'sqft_hr',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['production_rate']['surface_category'])->toBe('epoxy_floor');
});

it('rejects non-snake-case surface categories', function () {
    expect(fn () => $this->service->createProductionRate(
        name: 'Bad Rate',
        scope: 'interior',
        surfaceCategory: 'Epoxy Floor',
        productionRate: 100,
    ))->toThrow(\InvalidArgumentException::class);
});

it('rejects camelCase surface categories', function () {
    expect(fn () => $this->service->createProductionRate(
        name: 'Bad Rate',
        scope: 'interior',
        surfaceCategory: 'epoxyFloor',
        productionRate: 100,
    ))->toThrow(\InvalidArgumentException::class);
});

// =========================================================================
// PAINT CONFIG IN PLAN
// =========================================================================

it('executes plan with paint config', function () {
    $brand = \App\Models\PaintBrand::factory()->create();
    $paint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Duration Home',
        'scope_type' => 'interior',
    ]);

    $plan = [
        'paint_config' => [
            'mode' => 'single',
            'paint_product_id' => $paint->id,
        ],
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Hall',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 8, 'width' => 4, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ],
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();

    expect($this->estimate->fresh()->default_paint_product_id)->toBe($paint->id);
});

// =========================================================================
// COST ITEMS IN PLAN
// =========================================================================

it('executes plan with cost items on areas', function () {
    $plan = [
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Exterior Wall',
                'scope_type' => 'exterior',
                'dimensions' => ['length' => 40, 'width' => 0, 'height' => 12, 'wall_count' => 1],
                'surfaces' => [
                    ['name' => 'Body/Siding', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ],
                'cost_items' => [
                    ['name' => 'Scaffolding Rental', 'cost_type' => 'rental', 'quantity' => 1, 'unit_cost' => 450],
                ],
            ],
        ],
    ];

    $result = $this->service->executePlan($this->estimate, $plan);

    expect($result['success'])->toBeTrue()
        ->and($result['errors'])->toBeEmpty();

    $area = $this->estimate->fresh()->areas->first();
    expect($area->costItems)->toHaveCount(1);
    expect($area->costItems->first()->name)->toBe('Scaffolding Rental');
});

// =========================================================================
// UPDATE EXISTING SURFACES
// =========================================================================

it('updates surface names on existing areas', function () {
    // Create an area first
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Front of House',
            'scope_type' => 'exterior',
            'dimensions' => ['length' => 0, 'width' => 50, 'height' => 12, 'wall_count' => 1],
            'surfaces' => [
                ['name' => 'Trim', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ['name' => 'Other Trim', 'surface_category' => 'baseboard', 'production_rate_id' => $this->baseboardRate->id, 'coats' => 2],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $area = $this->estimate->fresh()->areas->first();
    $surfaces = $area->surfaces;

    // Now rename the surfaces
    $updatePlan = [
        'update_areas' => [[
            'area_id' => $area->id,
            'update_surfaces' => [
                ['surface_id' => $surfaces[0]->id, 'name' => 'Body/Siding'],
                ['surface_id' => $surfaces[1]->id, 'name' => 'Fascia'],
            ],
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $updatePlan);

    expect($result['success'])->toBeTrue()
        ->and($result['surfaces_updated'])->toBe(2)
        ->and($result['errors'])->toBeEmpty();

    $area->refresh();
    expect($area->surfaces[0]->fresh()->name)->toBe('Body/Siding');
    expect($area->surfaces[1]->fresh()->name)->toBe('Fascia');
});

it('updates coats on existing surfaces', function () {
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 14, 'width' => 12, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $area = $this->estimate->fresh()->areas->first();
    $surfaces = $area->surfaces;

    $updatePlan = [
        'update_areas' => [[
            'area_id' => $area->id,
            'update_surfaces' => [
                ['surface_id' => $surfaces[0]->id, 'coats' => 3],
                ['surface_id' => $surfaces[1]->id, 'coats' => 3],
            ],
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $updatePlan);

    expect($result['success'])->toBeTrue()
        ->and($result['surfaces_updated'])->toBe(2);

    expect($surfaces[0]->fresh()->coats)->toBe(3);
    expect($surfaces[1]->fresh()->coats)->toBe(3);
});

it('adds surfaces to existing areas', function () {
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Bedroom',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 12, 'width' => 10, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $area = $this->estimate->fresh()->areas->first();
    expect($area->surfaces)->toHaveCount(1);

    $updatePlan = [
        'update_areas' => [[
            'area_id' => $area->id,
            'add_surfaces' => [
                ['name' => 'Crown Molding', 'surface_category' => 'trim', 'production_rate_id' => $this->baseboardRate->id, 'coats' => 2],
            ],
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $updatePlan);

    expect($result['success'])->toBeTrue()
        ->and($result['surfaces_created'])->toBe(1);

    $area->refresh();
    expect($area->surfaces)->toHaveCount(2);
    expect($area->surfaces->last()->name)->toBe('Crown Molding');
});

it('removes surfaces from existing areas', function () {
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Kitchen',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 12, 'width' => 14, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ['name' => 'Door', 'surface_category' => 'doors', 'production_rate_id' => $this->doorRate->id, 'coats' => 2, 'quantity_override' => 1],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $area = $this->estimate->fresh()->areas->first();
    $doorSurface = $area->surfaces->firstWhere('name', 'Door');

    $updatePlan = [
        'update_areas' => [[
            'area_id' => $area->id,
            'remove_surface_ids' => [$doorSurface->id],
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $updatePlan);

    expect($result['success'])->toBeTrue();
    expect($area->fresh()->surfaces)->toHaveCount(2);
});

it('renames an existing area', function () {
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Room 1',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 12, 'width' => 10, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $area = $this->estimate->fresh()->areas->first();

    $updatePlan = [
        'update_areas' => [[
            'area_id' => $area->id,
            'name' => 'Master Bedroom',
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $updatePlan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_updated'])->toBe(1);

    expect($area->fresh()->name)->toBe('Master Bedroom');
});

it('resizes an existing area and recalculates surface quantities', function () {
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 10, 'width' => 10, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $area = $this->estimate->fresh()->areas->first();
    $originalWallSqft = (float) $area->wall_sqft;

    $updatePlan = [
        'update_areas' => [[
            'area_id' => $area->id,
            'dimensions' => ['length' => 20, 'width' => 15],
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $updatePlan);

    expect($result['success'])->toBeTrue();

    $area->refresh();
    expect((float) $area->wall_sqft)->toBeGreaterThan($originalWallSqft);
});

// =========================================================================
// APPLY TIERS TO EXISTING
// =========================================================================

it('applies tier pricing to all existing surfaces', function () {
    $brand = \App\Models\PaintBrand::factory()->create();
    $goodPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Budget Paint',
        'scope_type' => 'interior',
        'quality_tier' => 'good',
        'price_per_gallon' => 40,
    ]);
    $betterPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Mid Paint',
        'scope_type' => 'interior',
        'quality_tier' => 'better',
        'price_per_gallon' => 65,
    ]);
    $bestPaint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Premium Paint',
        'scope_type' => 'interior',
        'quality_tier' => 'best',
        'price_per_gallon' => 80,
    ]);

    // First, create areas WITHOUT tiers
    $createPlan = [
        'areas' => [
            [
                'source' => 'custom',
                'name' => 'Bedroom 1',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 12, 'width' => 10, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ],
            ],
            [
                'source' => 'custom',
                'name' => 'Bedroom 2',
                'scope_type' => 'interior',
                'dimensions' => ['length' => 11, 'width' => 10, 'height' => 9],
                'surfaces' => [
                    ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
                    ['name' => 'Ceiling', 'surface_category' => 'ceiling', 'production_rate_id' => $this->ceilingRate->id, 'coats' => 2],
                ],
            ],
        ],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    // Verify no tier overrides exist yet
    $surface = $this->estimate->fresh()->areas->first()->surfaces->first();
    expect($surface->tier_overrides)->toBeNull();

    // Now add tiers to everything
    $tierPlan = [
        'tier_config' => [
            'good' => ['paint_product_id' => $goodPaint->id, 'default_coats' => 1],
            'better' => ['paint_product_id' => $betterPaint->id, 'default_coats' => 2],
            'best' => ['paint_product_id' => $bestPaint->id, 'default_coats' => 2],
        ],
        'apply_tiers_to_existing' => true,
    ];

    $result = $this->service->executePlan($this->estimate, $tierPlan);

    expect($result['success'])->toBeTrue()
        ->and($result['tier_overrides_set'])->toBe(12) // 4 surfaces × 3 tiers
        ->and($result['errors'])->toBeEmpty();

    // Verify tier overrides now exist
    $surface->refresh();
    expect($surface->tier_overrides)->not->toBeNull()
        ->and($surface->tier_overrides)->toHaveKeys(['good', 'better', 'best', '_base']);
});

// =========================================================================
// MIXED CREATE + UPDATE
// =========================================================================

it('creates new areas and updates existing areas in one plan', function () {
    // Create initial area
    $createPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Room 1',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 12, 'width' => 10, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
            ],
        ]],
    ];
    $this->service->executePlan($this->estimate, $createPlan);

    $existingArea = $this->estimate->fresh()->areas->first();

    // Now create a new area AND update the existing one
    $mixedPlan = [
        'areas' => [[
            'source' => 'custom',
            'name' => 'Bedroom 2',
            'scope_type' => 'interior',
            'dimensions' => ['length' => 11, 'width' => 10, 'height' => 9],
            'surfaces' => [
                ['name' => 'Walls', 'surface_category' => 'walls', 'production_rate_id' => $this->wallRate->id, 'coats' => 2],
            ],
        ]],
        'update_areas' => [[
            'area_id' => $existingArea->id,
            'name' => 'Master Bedroom',
            'update_surfaces' => [
                ['surface_id' => $existingArea->surfaces->first()->id, 'coats' => 3],
            ],
        ]],
    ];

    $result = $this->service->executePlan($this->estimate, $mixedPlan);

    expect($result['success'])->toBeTrue()
        ->and($result['areas_created'])->toBe(1)
        ->and($result['areas_updated'])->toBe(1)
        ->and($result['surfaces_updated'])->toBe(1);

    expect($existingArea->fresh()->name)->toBe('Master Bedroom');
    expect($existingArea->fresh()->surfaces->first()->coats)->toBe(3);
    expect($this->estimate->fresh()->areas)->toHaveCount(2);
});
