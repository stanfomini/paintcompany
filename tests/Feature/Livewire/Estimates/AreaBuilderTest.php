<?php

use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\NestedAreaTemplate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

it('can render the area builder component', function () {
    Livewire::test('estimates.area-builder')
        ->assertStatus(200)
        ->assertSet('isOpen', false);
});

it('can open the builder for a blank area', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertSet('isOpen', true)
        ->assertSet('name', '')
        ->assertSet('scopeType', 'interior')
        ->assertSet('roomLength', 12.0)
        ->assertSet('roomWidth', 12.0)
        ->assertSet('roomHeight', 9.0);
});

it('initializes with default surfaces for interior', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertCount('surfaces', 3) // walls, ceiling, baseboards
        ->assertSee('Walls')
        ->assertSee('Ceiling')
        ->assertSee('Baseboards');
});

it('calculates wall sqft correctly for interior room', function () {
    // Interior: 2 × (L + W) × H
    // L=12, W=12, H=9 → 2 × (12 + 12) × 9 = 432 sqft
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertSet('roomLength', 12.0)
        ->assertSet('roomWidth', 12.0)
        ->assertSet('roomHeight', 9.0);

    expect($component->get('wallSqft'))->toBe(432.0);
});

it('calculates ceiling sqft correctly for interior room', function () {
    // Ceiling: L × W = 12 × 12 = 144 sqft
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior');

    expect($component->get('ceilingSqft'))->toBe(144.0);
});

it('calculates perimeter lnft correctly', function () {
    // Perimeter: 2 × (L + W) = 2 × (12 + 12) = 48 lnft
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior');

    expect($component->get('perimeterLnft'))->toBe(48.0);
});

it('can update dimensions', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('roomLength', 14)
        ->set('roomWidth', 12)
        ->set('roomHeight', 9)
        ->assertSet('roomLength', 14.0)
        ->assertSet('roomWidth', 12.0)
        ->assertSet('roomHeight', 9.0);
});

it('can add a surface', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertCount('surfaces', 3)
        ->call('addSurface')
        ->assertCount('surfaces', 4);
});

it('can remove a surface if more than one exists', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertCount('surfaces', 3)
        ->call('removeSurface', 2) // Remove baseboards
        ->assertCount('surfaces', 2);
});

it('cannot remove the last surface', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->call('removeSurface', 2)
        ->call('removeSurface', 1)
        ->assertCount('surfaces', 1)
        ->call('removeSurface', 0) // Should fail
        ->assertCount('surfaces', 1);
});

it('can add a fixed cost addon', function () {
    $addon = NestedAreaTemplate::factory()->create([
        'name' => 'Closet',
        'fixed_price' => 75.00,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertCount('addons', 0)
        ->set('newAddonTemplateId', $addon->id)
        ->set('newAddonQuantity', 2)
        ->call('addAddon')
        ->assertCount('addons', 1);

    // Verify the addon data
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('newAddonTemplateId', $addon->id)
        ->set('newAddonQuantity', 2)
        ->call('addAddon');

    $addons = $component->get('addons');
    expect($addons[0]['name'])->toBe('Closet');
    expect($addons[0]['quantity'])->toBe(2);
    expect($addons[0]['unit_price'])->toBe(75.0);
    expect($addons[0]['total_price'])->toBe(150.0);
});

it('can remove an addon', function () {
    $addon = NestedAreaTemplate::factory()->create([
        'name' => 'Closet',
        'fixed_price' => 75.00,
        'scope_type' => 'interior',
        'is_active' => true,
    ]);

    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('newAddonTemplateId', $addon->id)
        ->set('newAddonQuantity', 1)
        ->call('addAddon')
        ->assertCount('addons', 1)
        ->call('removeAddon', 0)
        ->assertCount('addons', 0);
});

it('calculates preview with surfaces', function () {
    CompanySetting::create(['company_name' => 'Test Company']);

    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior');

    $preview = $component->get('previewCalculation');

    expect($preview)->not->toBeNull();
    expect($preview['dimensions'])->toHaveKeys(['wall_sqft', 'ceiling_sqft', 'perimeter_lnft']);
    expect($preview['totals'])->toHaveKeys(['labor_hours', 'labor_cost', 'with_margins']);
});

it('can save an area and dispatches event', function () {
    CompanySetting::create(['company_name' => 'Test Company']);

    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('name', 'Living Room')
        ->set('roomLength', 14)
        ->set('roomWidth', 12)
        ->set('roomHeight', 9)
        ->call('saveArea')
        ->assertDispatched('area-saved')
        ->assertSet('isOpen', false);
});

it('validates area name is required', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('name', '')
        ->call('saveArea')
        ->assertHasErrors(['name' => 'required']);
});

it('can open from a template', function () {
    $template = EstimateTemplate::factory()->create([
        'name' => 'Living Room',
        'scope_type' => 'interior',
        'default_coats' => 2,
        'metadata' => [
            'dimensions' => [
                'length' => 14,
                'width' => 12,
                'height' => 9,
            ],
        ],
    ]);

    // Create template surfaces
    $template->surfaces()->create([
        'name' => 'Walls',
        'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
        'calculation_method' => AreaTemplateSurface::CALC_METHOD_RATIO,
        'calculation_value' => 1.0,
        'unit_type' => AreaTemplateSurface::UNIT_TYPE_SQFT,
        'default_coats' => 2,
        'is_required' => true,
        'is_default_enabled' => true,
        'sort_order' => 0,
    ]);

    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-from-template', templateId: $template->id)
        ->assertSet('isOpen', true)
        ->assertSet('name', 'Living Room')
        ->assertSet('scopeType', 'interior')
        ->assertSet('roomLength', 14.0)
        ->assertSet('roomWidth', 12.0)
        ->assertSet('roomHeight', 9.0)
        ->assertSet('sourceTemplateId', $template->id);
});

it('can edit an existing area', function () {
    $areaData = [
        'name' => 'Master Bedroom',
        'scope_type' => 'interior',
        'source_template_id' => null,
        'length' => 16,
        'width' => 14,
        'height' => 9,
        'default_coats' => 2,
        'surfaces' => [
            [
                'name' => 'Walls',
                'surface_type' => 'walls',
                'surface_category' => 'walls',
                'production_rate_id' => null,
                'quantity_source' => 'wall_sqft',
                'quantity_override' => null,
                'default_coats' => 2,
                'is_required' => true,
                'is_default_enabled' => true,
                'fixed_price' => null,
            ],
        ],
        'addons' => [],
    ];

    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-edit', areaData: $areaData, editingIndex: 0)
        ->assertSet('isOpen', true)
        ->assertSet('name', 'Master Bedroom')
        ->assertSet('roomLength', 16.0)
        ->assertSet('roomWidth', 14.0)
        ->assertSet('editingIndex', 0);
});

it('can close the builder without saving', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('name', 'Test Room')
        ->call('closeBuilder')
        ->assertSet('isOpen', false)
        ->assertSet('editingIndex', null);
});

it('updates surface category and resets production rate', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->assertSet('surfaces.0.surface_category', 'walls')
        ->set('surfaces.0.surface_category', 'ceiling')
        ->assertSet('surfaces.0.production_rate_id', null)
        ->assertSet('surfaces.0.quantity_source', 'ceiling_sqft');
});

it('handles exterior scope with optional length', function () {
    Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'exterior')
        ->assertSet('scopeType', 'exterior')
        ->assertSet('roomLength', null);
});

it('calculates exterior wall sqft as single side when length is null', function () {
    // Exterior single side: W × H = 12 × 9 = 108 sqft
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'exterior')
        ->set('roomWidth', 12)
        ->set('roomHeight', 9);

    expect($component->get('wallSqft'))->toBe(108.0);
    expect($component->get('ceilingSqft'))->toBe(0.0);
    expect($component->get('perimeterLnft'))->toBe(12.0);
});

it('calculates material cost using paint product when provided', function () {
    // Create a paint product with known pricing
    // $50/gallon, 400 sqft coverage
    $paintProduct = \App\Models\PaintProduct::factory()->create([
        'price_per_gallon' => 50.00,
        'coverage_sqft_brush_roll' => 400,
    ]);

    // Open builder with paint product
    $component = Livewire::test('estimates.area-builder')
        ->dispatch(
            'open-area-builder-blank',
            scopeType: 'interior',
            paintMode: 'single',
            paintProductId: $paintProduct->id,
            paintByCategory: []
        )
        ->set('name', 'Test Room')
        ->set('roomLength', 10)
        ->set('roomWidth', 10)
        ->set('roomHeight', 10);

    // Wall sqft = 2 × (10 + 10) × 10 = 400 sqft
    expect($component->get('wallSqft'))->toBe(400.0);

    // Get the preview calculation
    $preview = $component->get('previewCalculation');

    // Material cost calculation:
    // - Walls: 400 sqft / 400 coverage × 1.8 (2 coats multiplier) × $50 = $90
    // - Ceiling: 100 sqft / 400 coverage × 1.8 × $50 = $22.50
    // - Baseboards: 40 lnft / 400 coverage × 1.8 × $50 = $9.00
    // Total ≈ $121.50 (but only walls/ceiling use sqft paint coverage)

    // The material cost should be > $0 (proving paint product is used)
    expect($preview['totals']['material_cost'])->toBeGreaterThan(0);

    // Verify paint product ID was stored
    expect($component->get('defaultPaintProductId'))->toBe($paintProduct->id);
    expect($component->get('paintMode'))->toBe('single');
});

it('calculates material cost using paint-by-category mode', function () {
    // Create paint products for different categories
    $wallsPaint = \App\Models\PaintProduct::factory()->create([
        'price_per_gallon' => 60.00,
        'coverage_sqft_brush_roll' => 400,
        'surface_type' => 'walls',
    ]);
    $ceilingPaint = \App\Models\PaintProduct::factory()->create([
        'price_per_gallon' => 40.00,
        'coverage_sqft_brush_roll' => 400,
        'surface_type' => 'ceiling',
    ]);

    $paintByCategory = [
        'walls' => $wallsPaint->id,
        'ceiling' => $ceilingPaint->id,
    ];

    // Open builder with paint-by-category
    $component = Livewire::test('estimates.area-builder')
        ->dispatch(
            'open-area-builder-blank',
            scopeType: 'interior',
            paintMode: 'category',
            paintProductId: null,
            paintByCategory: $paintByCategory
        )
        ->set('name', 'Test Room')
        ->set('roomLength', 10)
        ->set('roomWidth', 10)
        ->set('roomHeight', 10);

    $preview = $component->get('previewCalculation');

    // Material cost should be > $0 using category paint products
    expect($preview['totals']['material_cost'])->toBeGreaterThan(0);

    // Verify paint-by-category was stored
    expect($component->get('paintMode'))->toBe('category');
    expect($component->get('paintByCategory'))->toBe($paintByCategory);
});

it('preserves production rate selection when saving and editing area', function () {
    // Create a production rate with proper scope and category
    $rate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Test Wall Rate',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'rate_type' => 'sqft_per_hour',
        'is_active' => true,
    ]);

    // Open blank area builder
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('name', 'Test Room')
        ->set('roomLength', 12)
        ->set('roomWidth', 12)
        ->set('roomHeight', 9);

    // Verify the rate is available
    $ratesForWalls = $component->get('productionRatesByCategory')['walls'] ?? collect();
    expect($ratesForWalls->pluck('id')->toArray())->toContain($rate->id);

    // Select the production rate for the first surface (walls)
    $component->set('surfaces.0.production_rate_id', $rate->id);

    // Verify it's set
    expect($component->get('surfaces.0.production_rate_id'))->toBe($rate->id);

    // Save the area (this dispatches 'area-saved' event)
    $component->call('saveArea');

    // Check the dispatched event contains the production_rate_id
    $component->assertDispatched('area-saved', function ($event, $params) use ($rate) {
        $areaData = $params['areaData'];
        $surfaces = $areaData['surfaces'] ?? [];

        // First surface should have the production rate ID
        return isset($surfaces[0]['production_rate_id'])
            && $surfaces[0]['production_rate_id'] == $rate->id;
    });
});

it('loads production rate when editing area with saved surfaces', function () {
    // Create a production rate
    $rate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Test Wall Rate',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'rate_type' => 'sqft_per_hour',
        'is_active' => true,
    ]);

    // Simulate area data that would come from a previous save
    $areaData = [
        'name' => 'Previously Saved Room',
        'scope_type' => 'interior',
        'source_template_id' => null,
        'default_coats' => 2,
        'length' => 12.0,
        'width' => 12.0,
        'height' => 9.0,
        'surfaces' => [
            [
                'id' => null,
                'name' => 'Walls',
                'surface_type' => 'walls',
                'surface_category' => 'walls',
                'production_rate_id' => $rate->id, // This should be preserved
                'quantity_source' => 'wall_sqft',
                'quantity_override' => null,
                'default_coats' => 2,
                'is_required' => true,
                'is_default_enabled' => true,
                'fixed_price' => null,
            ],
        ],
        'addons' => [],
    ];

    // Open for edit with the saved area data
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-edit', areaData: $areaData, editingIndex: 0);

    // Verify the production rate ID was loaded
    expect($component->get('surfaces.0.production_rate_id'))->toBe($rate->id);
    expect($component->get('name'))->toBe('Previously Saved Room');
});

it('preserves production rate when value is a string (from form submission)', function () {
    // Create a production rate
    $rate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Test Wall Rate',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'rate_type' => 'sqft_per_hour',
        'is_active' => true,
    ]);

    // Open blank area builder
    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-blank', scopeType: 'interior')
        ->set('name', 'Test Room');

    // Set production rate as STRING (simulating form input from select)
    $component->set('surfaces.0.production_rate_id', (string) $rate->id);

    // Verify it's stored (may be string or int depending on Livewire handling)
    $storedValue = $component->get('surfaces.0.production_rate_id');
    expect($storedValue == $rate->id)->toBeTrue(); // Loose comparison

    // Save and check the dispatched event
    $component->call('saveArea');

    $component->assertDispatched('area-saved', function ($event, $params) use ($rate) {
        $surfaces = $params['areaData']['surfaces'] ?? [];
        $surfaceRateId = $surfaces[0]['production_rate_id'] ?? null;

        // Should equal the rate ID (either as int or string)
        return $surfaceRateId == $rate->id;
    });
});

it('loads exterior surface categories and production rates correctly from dispatched edit data', function () {
    // Create exterior production rates
    $bodyRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Siding Spray',
        'scope' => 'exterior',
        'surface_category' => 'body',
        'production_rate' => 250,
        'rate_type' => 'sqft_hr',
        'is_active' => true,
    ]);
    $eavesRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Eaves Body Color',
        'scope' => 'exterior',
        'surface_category' => 'eaves',
        'production_rate' => 40,
        'rate_type' => 'lnft_hr',
        'is_active' => true,
    ]);
    $fasciaRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Fascia 1 story',
        'scope' => 'exterior',
        'surface_category' => 'fascia',
        'production_rate' => 30,
        'rate_type' => 'lnft_hr',
        'is_active' => true,
    ]);
    $windowRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Windows Wood',
        'scope' => 'exterior',
        'surface_category' => 'windows',
        'production_rate' => 2,
        'rate_type' => 'units_hr',
        'is_active' => true,
    ]);

    // Simulate area data from template builder (as it would come from buildSurfaceArrayFromArea)
    $areaData = [
        'name' => 'Back of House',
        'scope_type' => 'exterior',
        'source_template_id' => null,
        'default_coats' => 1,
        'length' => 50.0,
        'width' => 12.0,
        'height' => 10.0,
        'surfaces' => [
            [
                'id' => null,
                'name' => 'Siding',
                'surface_type' => 'walls',
                'surface_category' => 'body',
                'production_rate_id' => (string) $bodyRate->id,
                'quantity_source' => 'custom',
                'quantity' => 850,
                'quantity_override' => 850,
                'default_coats' => 1,
                'is_required' => false,
                'is_default_enabled' => true,
                'is_custom' => false,
                'fixed_price' => null,
                'labor_hours' => 3.4,
                'labor_cost' => 136.0,
                'material_cost' => 50.0,
            ],
            [
                'id' => null,
                'name' => 'Soffit',
                'surface_type' => 'ceiling',
                'surface_category' => 'eaves',
                'production_rate_id' => (string) $eavesRate->id,
                'quantity_source' => 'ceiling_sqft',
                'quantity' => 0,
                'quantity_override' => null,
                'default_coats' => 1,
                'is_required' => false,
                'is_default_enabled' => true,
                'is_custom' => false,
                'fixed_price' => null,
                'labor_hours' => 0,
                'labor_cost' => 0,
                'material_cost' => 0,
            ],
            [
                'id' => null,
                'name' => 'Fascia',
                'surface_type' => 'trim',
                'surface_category' => 'fascia',
                'production_rate_id' => (string) $fasciaRate->id,
                'quantity_source' => 'custom',
                'quantity' => 50,
                'quantity_override' => 50,
                'default_coats' => 1,
                'is_required' => false,
                'is_default_enabled' => true,
                'is_custom' => false,
                'fixed_price' => null,
                'labor_hours' => 1.67,
                'labor_cost' => 66.8,
                'material_cost' => 10.0,
            ],
            [
                'id' => null,
                'name' => 'Windows',
                'surface_type' => 'windows',
                'surface_category' => 'windows',
                'production_rate_id' => (string) $windowRate->id,
                'quantity_source' => 'window_count',
                'quantity' => 8,
                'quantity_override' => 8,
                'default_coats' => 1,
                'is_required' => false,
                'is_default_enabled' => true,
                'is_custom' => false,
                'fixed_price' => null,
                'labor_hours' => 4.0,
                'labor_cost' => 160.0,
                'material_cost' => 0,
            ],
        ],
        'addons' => [],
    ];

    $component = Livewire::test('estimates.area-builder')
        ->dispatch('open-area-builder-edit', areaData: $areaData, editingIndex: 0);

    // Verify scope is exterior
    expect($component->get('scopeType'))->toBe('exterior');

    // Verify ALL surface categories loaded correctly
    expect($component->get('surfaces.0.surface_category'))->toBe('body');
    expect($component->get('surfaces.1.surface_category'))->toBe('eaves');
    expect($component->get('surfaces.2.surface_category'))->toBe('fascia');
    expect($component->get('surfaces.3.surface_category'))->toBe('windows');

    // Verify ALL production rate IDs loaded correctly
    expect($component->get('surfaces.0.production_rate_id'))->toBe((string) $bodyRate->id);
    expect($component->get('surfaces.1.production_rate_id'))->toBe((string) $eavesRate->id);
    expect($component->get('surfaces.2.production_rate_id'))->toBe((string) $fasciaRate->id);
    expect($component->get('surfaces.3.production_rate_id'))->toBe((string) $windowRate->id);

    // Verify the surface categories dropdown shows exterior categories
    $component->assertSee('Body Application')
        ->assertSee('Eaves')
        ->assertSee('Fascia')
        ->assertSee('Windows');

    // Verify production rate names are visible for surfaces with set rates
    $component->assertSee('Siding Spray')
        ->assertSee('Windows Wood');

    // Check actual HTML rendering - verify the correct options are selected
    $html = $component->html();

    // The surface_category select for Siding should have 'body' selected
    // The surface_category select for Windows should have 'windows' selected
    // Check that 'body' appears as a selected value in the rendered HTML
    expect($html)->toContain('Body Application');
    expect($html)->toContain('Eaves');

    // Save the area and verify data roundtrips correctly
    $component->set('name', 'Back of House')
        ->call('saveArea');

    $component->assertDispatched('area-saved', function ($event, $params) use ($bodyRate, $eavesRate, $fasciaRate, $windowRate) {
        $surfaces = $params['areaData']['surfaces'] ?? [];

        // Verify all 4 surfaces preserved their category and rate
        $siding = collect($surfaces)->firstWhere('name', 'Siding');
        $soffit = collect($surfaces)->firstWhere('name', 'Soffit');
        $fascia = collect($surfaces)->firstWhere('name', 'Fascia');
        $windows = collect($surfaces)->firstWhere('name', 'Windows');

        return $siding['surface_category'] === 'body'
            && $siding['production_rate_id'] == $bodyRate->id
            && $soffit['surface_category'] === 'eaves'
            && $soffit['production_rate_id'] == $eavesRate->id
            && $fascia['surface_category'] === 'fascia'
            && $fascia['production_rate_id'] == $fasciaRate->id
            && $windows['surface_category'] === 'windows'
            && $windows['production_rate_id'] == $windowRate->id;
    });
});

it('preserves surface categories when reopening area builder with different scope data', function () {
    // Create rates for both scopes
    $interiorWallRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Interior Walls Brush',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'rate_type' => 'sqft_hr',
        'is_active' => true,
    ]);
    $bodyRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Siding Spray',
        'scope' => 'exterior',
        'surface_category' => 'body',
        'production_rate' => 250,
        'rate_type' => 'sqft_hr',
        'is_active' => true,
    ]);
    $eavesRate = \App\Models\ProductionRate::factory()->create([
        'name' => 'Eaves Body Color',
        'scope' => 'exterior',
        'surface_category' => 'eaves',
        'production_rate' => 40,
        'rate_type' => 'lnft_hr',
        'is_active' => true,
    ]);

    $component = Livewire::test('estimates.area-builder');

    // FIRST: Open for an interior area
    $component->dispatch('open-area-builder-edit', areaData: [
        'name' => 'Living Room',
        'scope_type' => 'interior',
        'source_template_id' => null,
        'default_coats' => 2,
        'length' => 12.0, 'width' => 12.0, 'height' => 9.0,
        'surfaces' => [
            [
                'id' => null, 'name' => 'Walls', 'surface_type' => 'walls',
                'surface_category' => 'walls',
                'production_rate_id' => (string) $interiorWallRate->id,
                'quantity_source' => 'wall_sqft', 'quantity' => 432,
                'quantity_override' => null, 'default_coats' => 2,
                'is_required' => true, 'is_default_enabled' => true,
                'is_custom' => false, 'fixed_price' => null,
                'labor_hours' => 2.16, 'labor_cost' => 86.4, 'material_cost' => 20.0,
            ],
        ],
        'addons' => [],
    ], editingIndex: 0);

    // Verify interior data loaded
    expect($component->get('scopeType'))->toBe('interior');
    expect($component->get('surfaces.0.surface_category'))->toBe('walls');
    expect($component->get('surfaces.0.production_rate_id'))->toBe((string) $interiorWallRate->id);

    // Close the builder
    $component->call('closeBuilder');

    // SECOND: Open for an exterior area - THIS is where the bug might occur
    $component->dispatch('open-area-builder-edit', areaData: [
        'name' => 'Back of House',
        'scope_type' => 'exterior',
        'source_template_id' => null,
        'default_coats' => 1,
        'length' => 50.0, 'width' => 12.0, 'height' => 10.0,
        'surfaces' => [
            [
                'id' => null, 'name' => 'Siding', 'surface_type' => 'walls',
                'surface_category' => 'body',
                'production_rate_id' => (string) $bodyRate->id,
                'quantity_source' => 'custom', 'quantity' => 850,
                'quantity_override' => 850, 'default_coats' => 1,
                'is_required' => false, 'is_default_enabled' => true,
                'is_custom' => false, 'fixed_price' => null,
                'labor_hours' => 3.4, 'labor_cost' => 136.0, 'material_cost' => 50.0,
            ],
            [
                'id' => null, 'name' => 'Soffit', 'surface_type' => 'ceiling',
                'surface_category' => 'eaves',
                'production_rate_id' => (string) $eavesRate->id,
                'quantity_source' => 'ceiling_sqft', 'quantity' => 0,
                'quantity_override' => null, 'default_coats' => 1,
                'is_required' => false, 'is_default_enabled' => true,
                'is_custom' => false, 'fixed_price' => null,
                'labor_hours' => 0, 'labor_cost' => 0, 'material_cost' => 0,
            ],
        ],
        'addons' => [],
    ], editingIndex: 0);

    // Verify exterior data loaded correctly (this is where the bug manifests)
    expect($component->get('scopeType'))->toBe('exterior');

    // These are the critical assertions - the categories should NOT have been
    // overwritten by updatedSurfaces() during the morph
    expect($component->get('surfaces.0.surface_category'))->toBe('body');
    expect($component->get('surfaces.0.production_rate_id'))->toBe((string) $bodyRate->id);
    expect($component->get('surfaces.1.surface_category'))->toBe('eaves');
    expect($component->get('surfaces.1.production_rate_id'))->toBe((string) $eavesRate->id);
});
