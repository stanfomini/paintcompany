<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Property;
use App\Models\Substrate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::query()->delete();
});

describe('Painter Breakdown Access Control', function () {
    it('allows internal users to access the painter breakdown page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown', $estimate))
            ->assertStatus(200);
    });

    it('redirects agent users to their dashboard', function () {
        $user = User::factory()->agent()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown', $estimate))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects customer users to their dashboard', function () {
        $user = User::factory()->customer()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown', $estimate))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('denies access to unauthenticated users', function () {
        $estimate = Estimate::factory()->create();

        $this->get(route('estimates.painter-breakdown', $estimate))
            ->assertRedirect(route('login'));
    });
});

describe('Painter Breakdown Component Display', function () {
    it('renders the painter breakdown component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'project_type' => 'interior',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertStatus(200)
            ->assertSee('Work Order')
            ->assertSee($estimate->estimate_number);
    });

    it('shows crew-safe notice', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Crew-Safe View');
    });

    it('shows customer information from deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['name' => 'John Smith']);
        $customer = \App\Models\Customer::factory()->create([
            'user_id' => $customerUser->id,
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Main St',
            'city' => 'Anytown',
            'state' => 'CA',
            'zip' => '90210',
        ]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('John Smith')
            ->assertSee('123 Main St');
    });

    it('shows project type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'project_type' => 'exterior',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Exterior Painting');
    });
});

describe('Painter Breakdown Does NOT Show Pricing Data', function () {
    it('does not display customer price', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'total' => 2500.00,
            'subtotal' => 2300.00,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        // Should not see dollar amounts related to pricing
        $component->assertDontSee('$2,500.00')
            ->assertDontSee('$2,300.00')
            ->assertDontSee('Customer Price')
            ->assertDontSee('Gross Profit')
            ->assertDontSee('Net Profit')
            ->assertDontSee('Margin');
    });

    it('does not expose labor costs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create([
            'category_id' => $category->id,
        ]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 10.0,
            'labor_cost' => 210.00,
            'material_cost' => 50.00,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        // Should show hours but NOT costs
        $component->assertSee('10.0 hrs')
            ->assertDontSee('$210.00')
            ->assertDontSee('$50.00')
            ->assertDontSee('21.00/hr');
    });

    it('does not show material costs', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create([
            'category_id' => $category->id,
        ]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'material_cost' => 150.00,
            'paint_cost' => 125.00,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $component->assertDontSee('$150.00')
            ->assertDontSee('$125.00')
            ->assertDontSee('Material Cost');
    });
});

describe('Painter Breakdown Area Display', function () {
    it('shows area names and dimensions', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'length' => 15,
            'width' => 20,
            'height' => 9,
            'wall_sqft' => 630,
            'ceiling_sqft' => 300,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Living Room')
            ->assertSee("15' × 20' × 9' H")
            ->assertSee('930 sqft total');
    });

    it('shows surface details with quantities and coats', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create([
            'category_id' => $category->id,
            'name' => 'Walls - Standard',
        ]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Bedroom',
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => 'Walls',
            'quantity' => 380,
            'unit' => 'sqft',
            'coats' => 2,
            'labor_hours' => 3.2,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Walls')
            ->assertSee('380 sqft')
            ->assertSee('2 coats')
            ->assertSee('3.2 hrs');
    });

    it('shows substrate condition and prep requirements', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $substrate = Substrate::factory()->create([
            'name' => 'Peeling Paint',
            'requires_scrape' => true,
            'requires_prime' => true,
            'requires_sand' => true,
        ]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Hallway',
            'substrate_id' => $substrate->id,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Peeling Paint')
            ->assertSee('Scrape')
            ->assertSee('Prime')
            ->assertSee('Sand');
    });

    it('shows paint product information', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
        $product = PaintProduct::factory()->create([
            'paint_brand_id' => $brand->id,
            'name' => 'Duration',
            'sheen' => 'eggshell',
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Sherwin-Williams Duration')
            ->assertSee('Eggshell');
    });
});

describe('Painter Breakdown Job Totals', function () {
    it('shows correct job totals for labor and cleanup hours', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);

        $area1 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Room 1',
        ]);
        $area2 = EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Room 2',
        ]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area1->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 5.0,
            'is_included' => true,
        ]);
        EstimateAreaSurface::factory()->create([
            'area_id' => $area2->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 3.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        // Should show 8.0 labor hours total
        $component->assertSee('8.0'); // Labor hours

        // Should show 2 areas
        $component->assertSee('Areas');
    });

    it('shows area count', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        EstimateArea::factory()->count(4)->create([
            'estimate_id' => $estimate->id,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $totals = $component->get('jobTotals');
        expect($totals['area_count'])->toBe(4);
    });
});

describe('Painter Breakdown Materials List', function () {
    it('consolidates materials across all areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $brand = PaintBrand::factory()->create(['name' => 'Benjamin Moore']);
        $product = PaintProduct::factory()->create([
            'paint_brand_id' => $brand->id,
            'name' => 'Regal Select',
            'sheen' => 'satin',
            'coverage_sqft_brush_roll' => 400,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create();

        $area1 = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        $area2 = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area1->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'quantity' => 400,
            'coats' => 2,
            'paint_gallons' => 2,
            'is_included' => true,
        ]);
        EstimateAreaSurface::factory()->create([
            'area_id' => $area2->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'quantity' => 300,
            'coats' => 2,
            'paint_gallons' => 1,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $materials = $component->get('materialsNeeded');

        // Should consolidate into one entry with 3 gallons total
        expect($materials)->toHaveCount(1);
        expect($materials[0]['brand'])->toBe('Benjamin Moore');
        expect($materials[0]['name'])->toBe('Regal Select');
        expect($materials[0]['gallons'])->toBe(3.0);
    });

    it('shows materials needed section', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $brand = PaintBrand::factory()->create(['name' => 'PPG']);
        $product = PaintProduct::factory()->create([
            'paint_brand_id' => $brand->id,
            'name' => 'Manor Hall',
            'sheen' => 'flat',
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'paint_gallons' => 2,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Materials Needed')
            ->assertSee('PPG Manor Hall')
            ->assertSee('2 gallons');
    });
});

describe('Painter Breakdown Expand/Collapse', function () {
    it('can toggle area expansion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        // Areas start expanded
        expect($component->get('expandedAreas')[$area->id])->toBeTrue();

        // Toggle to collapse
        $component->call('toggleArea', $area->id);
        expect($component->get('expandedAreas')[$area->id])->toBeFalse();

        // Toggle to expand again
        $component->call('toggleArea', $area->id);
        expect($component->get('expandedAreas')[$area->id])->toBeTrue();
    });

    it('can expand all areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $area1 = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        $area2 = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        // Collapse all first
        $component->call('collapseAll');
        expect($component->get('expandedAreas'))->toBe([]);

        // Expand all
        $component->call('expandAll');
        expect($component->get('expandedAreas')[$area1->id])->toBeTrue();
        expect($component->get('expandedAreas')[$area2->id])->toBeTrue();
    });

    it('can collapse all areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $component->call('collapseAll');
        expect($component->get('expandedAreas'))->toBe([]);
    });
});

describe('Painter Breakdown Special Instructions', function () {
    it('shows estimate notes as special instructions', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'notes' => 'Customer requests no work before 9am. Dog in backyard.',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Special Instructions')
            ->assertSee('Customer requests no work before 9am')
            ->assertSee('Dog in backyard');
    });

    it('shows area-level notes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'notes' => 'Move refrigerator before painting behind it',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->assertSee('Move refrigerator before painting behind it');
    });
});

describe('Painter Breakdown Financials Tab', function () {
    it('defaults to work-order tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        expect($component->get('activeTab'))->toBe('work-order');
    });

    it('can switch to financials tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->call('setTab', 'financials');

        expect($component->get('activeTab'))->toBe('financials');
        $component->assertSee('For Subcontractor Owner Only');
    });

    it('can switch back to work-order tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->call('setTab', 'financials')
            ->call('setTab', 'work-order');

        expect($component->get('activeTab'))->toBe('work-order');
        $component->assertSee('Crew-Safe View');
    });

    it('shows materials with costs on financials tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
        $product = PaintProduct::factory()->create([
            'paint_brand_id' => $brand->id,
            'name' => 'Duration',
            'sheen' => 'eggshell',
            'price_per_gallon' => 65.00,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'paint_gallons' => 3,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $materialsWithCosts = $component->get('materialsWithCosts');

        expect($materialsWithCosts['items'])->toHaveCount(1);
        expect($materialsWithCosts['items'][0]['brand'])->toBe('Sherwin-Williams');
        expect($materialsWithCosts['items'][0]['price_per_gallon'])->toBe(65.00);
        expect($materialsWithCosts['items'][0]['gallons'])->toBe(3.0);
        expect($materialsWithCosts['items'][0]['paint_cost'])->toBe(195.00);
    });

    it('calculates recycle fee correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update(['recycle_fee_per_gallon' => 0.54]);

        $brand = PaintBrand::factory()->create(['name' => 'Test Brand']);
        $product = PaintProduct::factory()->create([
            'paint_brand_id' => $brand->id,
            'price_per_gallon' => 50.00,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'paint_gallons' => 4,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $materialsWithCosts = $component->get('materialsWithCosts');

        expect($materialsWithCosts['total_gallons'])->toBe(4.0);
        expect($materialsWithCosts['recycle_fee_rate'])->toBe(0.54);
        expect($materialsWithCosts['total_recycle_fee'])->toBe(2.16); // 4 × 0.54
    });

    it('calculates sundries breakdown correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update(['interior_sundries_cost_per_hour' => 3.30]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 10.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $sundriesBreakdown = $component->get('sundriesBreakdown');

        expect($sundriesBreakdown['labor_hours'])->toBe(10.0);
        expect($sundriesBreakdown['cost_per_hour'])->toBe(3.30);
        expect($sundriesBreakdown['total_cost'])->toBe(33.00); // 10 × 3.30
        expect($sundriesBreakdown['scope'])->toBe('interior');
    });

    it('calculates painter pay breakdown correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update([
            'interior_hourly_wage' => 21.00,
            'payroll_burden_multiplier' => 1.35,
            'interior_cleanup_hours_ratio' => 16.00,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 16.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $painterPay = $component->get('painterPayBreakdown');

        expect($painterPay['base_hourly_wage'])->toBe(21.00);
        expect($painterPay['payroll_burden_multiplier'])->toBe(1.35);
        expect($painterPay['payroll_burden_percent'])->toBe(35.0);
        expect($painterPay['effective_hourly_rate'])->toBe(28.35); // 21 × 1.35
        expect($painterPay['labor_hours'])->toBe(16.0);
        expect($painterPay['cleanup_hours'])->toBe(1.0); // 16 / 16
        expect($painterPay['total_hours'])->toBe(17.0);
        expect($painterPay['painter_earns_base'])->toBe(357.00); // 17 × 21
        expect($painterPay['company_pays_loaded'])->toBe(481.95); // 17 × 28.35
    });

    it('generates crew schedule grid', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update([
            'interior_hourly_wage' => 21.00,
            'interior_cleanup_hours_ratio' => 16.00,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 16.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        $crewGrid = $component->get('crewScheduleGrid');

        expect($crewGrid['grid'])->toHaveCount(4); // 1-4 painters
        expect($crewGrid['total_hours'])->toBe(17.0); // 16 labor + 1 cleanup

        // 1 painter: 17 hours / 8 hours per day = 2.1 days
        expect($crewGrid['grid'][0]['painters'])->toBe(1);
        expect($crewGrid['grid'][0]['days_needed'][8])->toBe(2.1);

        // 2 painters: 17 / 2 = 8.5 hours per painter / 8 = 1.1 days
        expect($crewGrid['grid'][1]['painters'])->toBe(2);
        expect($crewGrid['grid'][1]['days_needed'][8])->toBe(1.1);

        // Total job pay stays the same for all crew sizes
        expect($crewGrid['grid'][0]['total_job_pay'])->toBe($crewGrid['grid'][3]['total_job_pay']);
    });

    it('can adjust crew size', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        expect($component->get('crewSize'))->toBe(2); // Default

        $component->call('setCrewSize', 3);
        expect($component->get('crewSize'))->toBe(3);

        $component->call('setCrewSize', 1);
        expect($component->get('crewSize'))->toBe(1);

        // Test boundaries
        $component->call('setCrewSize', 0);
        expect($component->get('crewSize'))->toBe(1); // Min is 1

        $component->call('setCrewSize', 5);
        expect($component->get('crewSize'))->toBe(4); // Max is 4
    });

    it('can adjust hours per day', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate]);

        expect($component->get('hoursPerDay'))->toBe(8.0); // Default

        $component->call('setHoursPerDay', 6);
        expect($component->get('hoursPerDay'))->toBe(6.0);

        $component->call('setHoursPerDay', 10);
        expect($component->get('hoursPerDay'))->toBe(10.0);

        // Test boundaries
        $component->call('setHoursPerDay', 2);
        expect($component->get('hoursPerDay'))->toBe(4.0); // Min is 4

        $component->call('setHoursPerDay', 15);
        expect($component->get('hoursPerDay'))->toBe(12.0); // Max is 12
    });

    it('shows financials tab content correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update([
            'interior_hourly_wage' => 21.00,
            'payroll_burden_multiplier' => 1.35,
        ]);

        $brand = PaintBrand::factory()->create(['name' => 'Test Brand']);
        $product = PaintProduct::factory()->create([
            'paint_brand_id' => $brand->id,
            'name' => 'Test Paint',
            'price_per_gallon' => 50.00,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'paint_gallons' => 2,
            'labor_hours' => 8.0,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.painter-breakdown', ['estimate' => $estimate])
            ->call('setTab', 'financials')
            ->assertSee('Materials', escape: false)
            ->assertSee('Sundries', escape: false)
            ->assertSee('Painter Pay Calculator', escape: false)
            ->assertSee('Crew Size Calculator', escape: false)
            ->assertSee('Test Brand Test Paint', escape: false)
            ->assertSee('$21.00/hr', escape: false)
            ->assertSee('+35%', escape: false);
    });
});

describe('Painter Breakdown PDF Routes', function () {
    it('allows internal users to access work order PDF', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.work-order', $estimate))
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    });

    it('allows internal users to access financials PDF', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.financials', $estimate))
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    });

    it('redirects agent users to their dashboard for PDF routes', function () {
        $user = User::factory()->agent()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to agent dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.work-order', $estimate))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.financials', $estimate))
            ->assertRedirect(route('agent.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects customer users to their dashboard for PDF routes', function () {
        $user = User::factory()->customer()->create();
        $estimate = Estimate::factory()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.work-order', $estimate))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');

        $this->actingAs($user)
            ->get(route('estimates.painter-breakdown.pdf.financials', $estimate))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('redirects unauthenticated users to login for PDF routes', function () {
        $estimate = Estimate::factory()->create();

        $this->get(route('estimates.painter-breakdown.pdf.work-order', $estimate))
            ->assertRedirect(route('login'));

        $this->get(route('estimates.painter-breakdown.pdf.financials', $estimate))
            ->assertRedirect(route('login'));
    });
});
