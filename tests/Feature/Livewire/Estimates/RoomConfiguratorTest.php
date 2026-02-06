<?php

use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    // Ensure company settings exist with known PBP values
    CompanySetting::query()->delete();
    CompanySetting::create([
        'company_name' => 'Test Painting Company',
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_cleanup_labor_ratio' => 16,
        'exterior_cleanup_labor_ratio' => 20,
        'interior_sundries_per_labor_hour' => 3.30,
        'exterior_sundries_per_labor_hour' => 1.00,
        'profit_margin_percent' => 30,
        'overhead_percent' => 12,
        'interior_scope_markup' => 50,
        'exterior_scope_markup' => 41,
    ]);

    // Clear any cached view templates
    app('view.finder')->flush();
});

describe('RoomConfigurator component', function () {
    it('renders the room configurator component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('Add Room');
    });

    it('displays existing areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);
        EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'length' => 15,
            'width' => 12,
            'height' => 9,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->assertSee('Living Room');
    });
});

describe('RoomConfigurator add room from template', function () {
    it('can add room from estimate template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        // Create template with surfaces using valid icon
        $category = EstimateTemplateCategory::factory()->create(['name' => 'Interior Rooms']);
        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 200,
            'is_active' => true,
        ]);

        $template = EstimateTemplate::factory()
            ->forCategory($category)
            ->interior()
            ->create([
                'name' => 'Living Room',
                'icon' => 'home', // Use valid Flux icon
                'is_active' => true,
                'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
                'metadata' => [
                    'dimensions' => ['length' => 14, 'width' => 12, 'height' => 9],
                ],
            ]);

        AreaTemplateSurface::factory()->create([
            'estimate_template_id' => $template->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'production_rate_id' => $rate->id,
            'is_default_enabled' => true,
            'default_coats' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('addFromTemplate', $template->id)
            ->assertDispatched('area-added');

        // Verify area was created
        $estimate->refresh();
        expect($estimate->areas)->toHaveCount(1);
        expect($estimate->areas->first()->name)->toBe('Living Room');
        expect((float) $estimate->areas->first()->length)->toBe(14.0);
        expect((float) $estimate->areas->first()->width)->toBe(12.0);
        expect((float) $estimate->areas->first()->height)->toBe(9.0);
    });

    it('creates surfaces from template', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $category = EstimateTemplateCategory::factory()->create();
        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $wallRate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 200,
            'is_active' => true,
        ]);
        $ceilingRate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'ceiling',
            'production_rate' => 150,
            'is_active' => true,
        ]);

        $template = EstimateTemplate::factory()
            ->forCategory($category)
            ->interior()
            ->create([
                'icon' => 'home', // Use valid Flux icon
                'is_active' => true,
                'visibility' => EstimateTemplate::VISIBILITY_SYSTEM,
            ]);

        AreaTemplateSurface::factory()->create([
            'estimate_template_id' => $template->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'production_rate_id' => $wallRate->id,
            'is_default_enabled' => true,
        ]);

        AreaTemplateSurface::factory()->create([
            'estimate_template_id' => $template->id,
            'name' => 'Ceiling',
            'surface_type' => 'ceiling',
            'production_rate_id' => $ceilingRate->id,
            'is_default_enabled' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('addFromTemplate', $template->id);

        $estimate->refresh();
        $area = $estimate->areas->first();
        expect($area->surfaces)->toHaveCount(2);
        expect($area->surfaces->pluck('name')->toArray())->toContain('Walls', 'Ceiling');
    });
});

describe('RoomConfigurator add custom room', function () {
    it('can add custom room with dimensions', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        // Create a production rate for default surfaces
        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 200,
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('openQuickAddDrawer')
            ->assertSet('showQuickAddDrawer', true)
            ->set('newRoomName', 'Custom Office')
            ->set('newRoomScope', 'interior')
            ->set('newLength', 10)
            ->set('newWidth', 12)
            ->set('newHeight', 9)
            ->call('addCustomRoom')
            ->assertDispatched('area-added')
            ->assertSet('showQuickAddDrawer', false);

        $estimate->refresh();
        expect($estimate->areas)->toHaveCount(1);

        $area = $estimate->areas->first();
        expect($area->name)->toBe('Custom Office');
        expect($area->scope_type)->toBe('interior');
        expect((float) $area->length)->toBe(10.0);
        expect((float) $area->width)->toBe(12.0);
        expect((float) $area->height)->toBe(9.0);
    });

    it('validates custom room required fields', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->set('newRoomName', '')
            ->set('newLength', 0)
            ->call('addCustomRoom')
            ->assertHasErrors(['newRoomName', 'newLength']);
    });

    it('calculates computed sqft values for custom room', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        // Create a production rate for default surfaces
        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 200,
            'is_active' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->set('newRoomName', 'Test Room')
            ->set('newLength', 12)
            ->set('newWidth', 14)
            ->set('newHeight', 9)
            ->call('addCustomRoom');

        $estimate->refresh();
        $area = $estimate->areas->first();

        // Wall sqft = 2 × (L + W) × H = 2 × (12 + 14) × 9 = 468
        expect((float) $area->wall_sqft)->toBe(468.0);

        // Ceiling sqft = L × W = 12 × 14 = 168
        expect((float) $area->ceiling_sqft)->toBe(168.0);
    });
});

describe('RoomConfigurator surface management', function () {
    it('can toggle surface inclusion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'is_active' => true,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
        ]);

        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('toggleSurface', $area->id, $surface->id)
            ->assertDispatched('surface-toggled');

        $surface->refresh();
        expect($surface->is_included)->toBeFalse();

        // Toggle back
        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('toggleSurface', $area->id, $surface->id);

        $surface->refresh();
        expect($surface->is_included)->toBeTrue();
    });

    it('can update surface production rate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $originalRate = ProductionRate::factory()->create([
            'name' => 'Textured Walls same color',
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 265,
            'is_active' => true,
        ]);
        $newRate = ProductionRate::factory()->create([
            'name' => 'Textured Walls change color',
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 150,
            'is_active' => true,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'scope_type' => 'interior',
        ]);

        $surface = EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $originalRate->id,
            'quantity' => 400,
            'coats' => 2,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('updateSurfaceRate', $area->id, $surface->id, $newRate->id)
            ->assertDispatched('surface-updated');

        $surface->refresh();
        expect($surface->production_rate_id)->toBe($newRate->id);
        expect((float) $surface->production_rate_value)->toBe(150.0);
    });
});

describe('RoomConfigurator nested items', function () {
    it('can add nested item to area', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Master Bedroom',
        ]);

        $nestedTemplate = NestedAreaTemplate::factory()->smallCloset()->create();

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('openNestedItemModal', $area->id)
            ->assertSet('showNestedItemModal', true)
            ->assertSet('targetAreaIdForNested', $area->id)
            ->set('selectedNestedTemplateId', $nestedTemplate->id)
            ->call('addNestedItem')
            ->assertDispatched('nested-item-added')
            ->assertSet('showNestedItemModal', false);

        // Verify nested area was created as child
        $area->refresh();
        expect($area->childAreas)->toHaveCount(1);
        expect($area->childAreas->first()->name)->toBe('Small Closet');
        expect((float) $area->childAreas->first()->fixed_price_amount)->toBe(95.0);
    });

    it('can add standalone nested item', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $nestedTemplate = NestedAreaTemplate::factory()->laundryRoom()->create();

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('openNestedItemModal') // No area ID = standalone
            ->assertSet('addAsStandalone', true)
            ->set('selectedNestedTemplateId', $nestedTemplate->id)
            ->call('addNestedItem')
            ->assertDispatched('nested-item-added');

        $estimate->refresh();
        $standaloneArea = $estimate->areas->first();
        expect($standaloneArea->name)->toBe('Laundry Room');
        expect($standaloneArea->nested_area_template_id)->toBe($nestedTemplate->id);
        expect($standaloneArea->is_fixed_price)->toBeTrue();
        expect((float) $standaloneArea->fixed_price_amount)->toBe(225.0);
    });

    it('can remove nested item from area', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $nestedTemplate = NestedAreaTemplate::factory()->smallCloset()->create();

        $parentArea = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Bedroom',
        ]);

        $nestedArea = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'parent_area_id' => $parentArea->id,
            'name' => 'Small Closet',
            'nested_area_template_id' => $nestedTemplate->id,
            'is_fixed_price' => true,
            'fixed_price_amount' => 95.00,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('removeNestedItem', $parentArea->id, $nestedArea->id)
            ->assertDispatched('nested-item-removed');

        expect(EstimateArea::find($nestedArea->id))->toBeNull();
    });
});

describe('RoomConfigurator dimension updates', function () {
    it('recalculates prices when dimensions change', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'surface_category' => 'walls',
            'production_rate' => 200,
            'is_active' => true,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'scope_type' => 'interior',
            'length' => 12,
            'width' => 12,
            'height' => 9,
        ]);

        // Create surface with explicit initial values
        // Wall sqft for 12x12x9 = 2 × (12 + 12) × 9 = 432
        $surface = EstimateAreaSurface::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 432, // Initial wall sqft
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 200,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'labor_hours' => 4.32,
            'labor_cost' => 122.47,
            'material_cost' => 86.40,
            'total' => 300.00,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        $originalQuantity = (float) $surface->quantity;

        // Update dimensions - larger room should have more sqft
        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('updateAreaDimension', $area->id, 'length', 18)
            ->assertDispatched('area-updated');

        $surface->refresh();
        $area->refresh();

        // Verify quantity was updated based on new wall sqft
        // New wall sqft = 2 × (18 + 12) × 9 = 540
        expect((float) $area->wall_sqft)->toBe(540.0);
        expect((float) $surface->quantity)->toBe(540.0);
        expect((float) $surface->quantity)->toBeGreaterThan($originalQuantity);
    });
});

describe('RoomConfigurator area deletion', function () {
    it('can delete an area', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'is_active' => true,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Room To Delete',
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('deleteArea', $area->id)
            ->assertDispatched('area-deleted');

        expect(EstimateArea::find($area->id))->toBeNull();
        expect(EstimateAreaSurface::where('area_id', $area->id)->count())->toBe(0);
    });

    it('deletes child areas when parent is deleted', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $parentArea = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Parent Room',
        ]);

        $childArea = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'parent_area_id' => $parentArea->id,
            'name' => 'Child Closet',
            'is_fixed_price' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->call('deleteArea', $parentArea->id);

        expect(EstimateArea::find($parentArea->id))->toBeNull();
        expect(EstimateArea::find($childArea->id))->toBeNull();
    });
});

describe('RoomConfigurator totals calculation', function () {
    it('calculates area total including surfaces and nested items', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'scope' => 'interior',
            'is_active' => true,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        // Create surfaces - totals are auto-calculated by model's saving hook
        // For: quantity=400, rate=200, coats=2, hourly_rate=28.35, material_cost=0.10
        // labor_hours = (400 / 200) * 2 = 4
        // labor_cost = 4 * 28.35 = 113.40
        // material_cost = 400 * 0.10 * 2 = 80
        // total = 113.40 + 80 = 193.40
        $surface1 = EstimateAreaSurface::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 400,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 200,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // For: quantity=200, rate=150, coats=2
        // labor_hours = (200 / 150) * 2 = 2.67
        // labor_cost = 2.67 * 28.35 = 75.69 (rounded)
        // material_cost = 200 * 0.10 * 2 = 40
        // total = 75.69 + 40 = 115.69 (approximately)
        $surface2 = EstimateAreaSurface::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Ceiling',
            'surface_type' => 'ceiling',
            'quantity' => 200,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 150,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'is_included' => true,
            'sort_order' => 1,
        ]);

        $childArea = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'parent_area_id' => $area->id,
            'is_fixed_price' => true,
            'fixed_price_amount' => 95.00,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate]);

        $total = $component->instance()->calculateAreaTotal($area->fresh(['surfaces', 'childAreas']));

        // Surface 1: 193.40 + Surface 2: 115.69 + Nested: 95.00 = 404.09
        // The actual calculation is: labor + material for each surface + fixed price
        $expectedTotal = (float) $surface1->fresh()->total + (float) $surface2->fresh()->total + 95.00;
        expect($total)->toBe($expectedTotal);
    });

    it('excludes non-included surfaces from total', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'is_active' => true,
        ]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        // Create an included surface
        $includedSurface = EstimateAreaSurface::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 400,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 200,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // Create a non-included surface
        EstimateAreaSurface::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Ceiling',
            'surface_type' => 'ceiling',
            'quantity' => 200,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 150,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'is_included' => false, // Not included - should be excluded from total
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate]);

        $total = $component->instance()->calculateAreaTotal($area->fresh(['surfaces', 'childAreas']));

        // Only included surface should be counted
        $expectedTotal = (float) $includedSurface->fresh()->total;
        expect($total)->toBe($expectedTotal);
    });

    it('calculates overall areas total', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $rateCategory = ProductionRateCategory::factory()->interior()->create(['is_active' => true]);
        $rate = ProductionRate::factory()->create([
            'category_id' => $rateCategory->id,
            'is_active' => true,
        ]);

        // Area 1
        $area1 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Room 1',
        ]);
        $surface1 = EstimateAreaSurface::create([
            'area_id' => $area1->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 400,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 200,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        // Area 2
        $area2 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Room 2',
        ]);
        $surface2 = EstimateAreaSurface::create([
            'area_id' => $area2->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'surface_type' => 'walls',
            'quantity' => 300,
            'unit' => 'sqft',
            'coats' => 2,
            'production_rate_value' => 200,
            'hourly_labor_rate' => 28.35,
            'material_cost_per_unit' => 0.10,
            'is_included' => true,
            'sort_order' => 0,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate]);

        $areasTotal = $component->instance()->areasTotal;

        // Sum of both surface totals (calculated by model)
        $expectedTotal = (float) $surface1->fresh()->total + (float) $surface2->fresh()->total;
        expect($areasTotal)->toBe($expectedTotal);
    });
});

describe('RoomConfigurator accordion behavior', function () {
    it('expands first area by default', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate]);

        expect($component->get('expandedAreaId'))->toBe($area->id);
    });

    it('can toggle area expansion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $area1 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'sort_order' => 0,
        ]);

        $area2 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Bedroom',
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->assertSet('expandedAreaId', $area1->id)
            ->call('toggleAreaExpansion', $area2->id)
            ->assertSet('expandedAreaId', $area2->id);
    });
});

describe('RoomConfigurator refresh event', function () {
    it('responds to refresh-areas event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $component = Livewire::actingAs($user)
            ->test('estimates.room-configurator', ['estimate' => $estimate])
            ->dispatch('refresh-areas')
            ->assertStatus(200);
    });
});
