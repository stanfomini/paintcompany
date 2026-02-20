<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::query()->delete();
});

describe('Job Painter Breakdown Access Control', function () {
    it('allows internal users with permission to access the page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->for(Estimate::factory(), 'estimate')->create();

        $this->actingAs($user)
            ->get(route('jobs.painter-breakdown', $job))
            ->assertStatus(200);
    });

    it('redirects agent users away from painter breakdown', function () {
        $user = User::factory()->agent()->create();
        $job = Job::factory()->for(Estimate::factory(), 'estimate')->create();

        $this->actingAs($user)
            ->get(route('jobs.painter-breakdown', $job))
            ->assertRedirect();
    });

    it('redirects customer users away from painter breakdown', function () {
        $user = User::factory()->customer()->create();
        $job = Job::factory()->for(Estimate::factory(), 'estimate')->create();

        $this->actingAs($user)
            ->get(route('jobs.painter-breakdown', $job))
            ->assertRedirect();
    });

    it('redirects unauthenticated users to login', function () {
        $job = Job::factory()->for(Estimate::factory(), 'estimate')->create();

        $this->get(route('jobs.painter-breakdown', $job))
            ->assertRedirect(route('login'));
    });
});

describe('Job Painter Breakdown Component Display', function () {
    it('renders with Work Order tab by default', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        expect($component->get('activeTab'))->toBe('work-order');
        $component->assertStatus(200)
            ->assertSee('Work Order')
            ->assertSee('Crew-Safe View');
    });

    it('shows job number and estimate number in header', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create([
            'estimate_number' => 'EST-2026-00042',
        ]);
        $job = Job::factory()->for($estimate, 'estimate')->create([
            'job_number' => 'JOB-2026-00007',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->assertSee('JOB-2026-00007')
            ->assertSee('EST-2026-00042');
    });

    it('shows project type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'exterior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->assertSee('Exterior Painting');
    });
});

describe('Job Painter Breakdown Tab Switching', function () {
    it('can switch to financials tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->call('setTab', 'financials');

        expect($component->get('activeTab'))->toBe('financials');
        $component->assertSee('For Subcontractor Owner Only');
    });

    it('can switch back to work-order tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->call('setTab', 'financials')
            ->call('setTab', 'work-order');

        expect($component->get('activeTab'))->toBe('work-order');
        $component->assertSee('Crew-Safe View');
    });
});

describe('Job Painter Breakdown Expand/Collapse', function () {
    it('starts with all areas expanded', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        expect($component->get('expandedAreas')[$area->id])->toBeTrue();
    });

    it('can toggle area expansion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

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
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area1 = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        $area2 = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

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
        $job = Job::factory()->for($estimate, 'estimate')->create();
        EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('collapseAll');
        expect($component->get('expandedAreas'))->toBe([]);
    });
});

describe('Job Painter Breakdown Painters', function () {
    it('can add a painter', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        expect($component->get('painters'))->toHaveCount(0);

        $component->call('addPainter');
        expect($component->get('painters'))->toHaveCount(1);
        expect($component->get('painters.0.name'))->toBe('');
        expect($component->get('painters.0.hours'))->toBe(0);
    });

    it('can add multiple painters', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->call('addPainter')
            ->call('addPainter')
            ->call('addPainter');

        expect($component->get('painters'))->toHaveCount(3);
    });

    it('can remove a painter', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->call('addPainter')
            ->call('addPainter');

        expect($component->get('painters'))->toHaveCount(2);

        $component->call('removePainter', 0);
        expect($component->get('painters'))->toHaveCount(1);
    });

    it('re-indexes painters after removal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->call('addPainter')
            ->call('addPainter')
            ->call('addPainter');

        // Remove the middle painter
        $component->call('removePainter', 1);

        $painters = $component->get('painters');
        expect($painters)->toHaveCount(2);
        // Keys should be re-indexed to 0 and 1
        expect(array_keys($painters))->toBe([0, 1]);
    });
});

describe('Job Painter Breakdown Save Actuals', function () {
    it('saves actual paint costs and painters to the estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        // Set actual paint costs
        $component->set('actualPaintCosts', ['1' => 45.00, '2' => 30.00]);

        // Add a painter
        $component->call('addPainter');
        $component->set('painters.0.name', 'John Doe');
        $component->set('painters.0.hourly_rate', 22.00);
        $component->set('painters.0.hours', 16);

        // Save
        $component->call('saveActuals');

        // Verify data was saved to the estimate
        $estimate->refresh();
        expect($estimate->actual_paint_costs)->toEqual(['1' => 45.00, '2' => 30.00]);
        expect($estimate->actual_painters)->toHaveCount(1);
        expect($estimate->actual_painters[0]['name'])->toBe('John Doe');
    });
});

describe('Job Painter Breakdown Crew Size Calculator', function () {
    it('defaults to crew size of 2', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        expect($component->get('crewSize'))->toBe(2);
    });

    it('can set crew size', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('setCrewSize', 3);
        expect($component->get('crewSize'))->toBe(3);

        $component->call('setCrewSize', 1);
        expect($component->get('crewSize'))->toBe(1);
    });

    it('clamps crew size to minimum of 1', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('setCrewSize', 0);
        expect($component->get('crewSize'))->toBe(1);

        $component->call('setCrewSize', -1);
        expect($component->get('crewSize'))->toBe(1);
    });

    it('clamps crew size to maximum of 4', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('setCrewSize', 5);
        expect($component->get('crewSize'))->toBe(4);

        $component->call('setCrewSize', 10);
        expect($component->get('crewSize'))->toBe(4);
    });
});

describe('Job Painter Breakdown Hours Per Day', function () {
    it('defaults to 8 hours per day', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        expect($component->get('hoursPerDay'))->toBe(8.0);
    });

    it('can set hours per day', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('setHoursPerDay', 6);
        expect($component->get('hoursPerDay'))->toBe(6.0);

        $component->call('setHoursPerDay', 10);
        expect($component->get('hoursPerDay'))->toBe(10.0);
    });

    it('clamps hours per day to minimum of 4', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('setHoursPerDay', 2);
        expect($component->get('hoursPerDay'))->toBe(4.0);

        $component->call('setHoursPerDay', 0);
        expect($component->get('hoursPerDay'))->toBe(4.0);
    });

    it('clamps hours per day to maximum of 12', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->call('setHoursPerDay', 15);
        expect($component->get('hoursPerDay'))->toBe(12.0);

        $component->call('setHoursPerDay', 24);
        expect($component->get('hoursPerDay'))->toBe(12.0);
    });
});

describe('Job Painter Breakdown Auto-Select Package', function () {
    it('auto-selects the selected package without showing tier selector', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        // The component auto-detects the selected package in mount()
        // Without packages, viewingPackageId should be null
        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        expect($component->get('viewingPackageId'))->toBeNull();
    });
});

describe('Job Painter Breakdown Area Display', function () {
    it('shows area names and dimensions', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        EstimateArea::factory()->create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'length' => 15,
            'width' => 20,
            'height' => 9,
            'wall_sqft' => 630,
            'ceiling_sqft' => 300,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
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
        $job = Job::factory()->for($estimate, 'estimate')->create();
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
            ->test('jobs.painter-breakdown', ['job' => $job])
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
        $job = Job::factory()->for($estimate, 'estimate')->create();
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
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->assertSee('Peeling Paint')
            ->assertSee('Scrape')
            ->assertSee('Prime')
            ->assertSee('Sand');
    });
});

describe('Job Painter Breakdown Job Totals', function () {
    it('shows correct total labor hours across areas', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();

        $area1 = EstimateArea::factory()->create(['estimate_id' => $estimate->id, 'name' => 'Room 1']);
        $area2 = EstimateArea::factory()->create(['estimate_id' => $estimate->id, 'name' => 'Room 2']);

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
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $component->assertSee('8.0');
    });

    it('shows area count', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->for($estimate, 'estimate')->create();

        EstimateArea::factory()->count(4)->create(['estimate_id' => $estimate->id]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $totals = $component->get('jobTotals');
        expect($totals['area_count'])->toBe(4);
    });
});

describe('Job Painter Breakdown Financials Tab Content', function () {
    it('calculates sundries breakdown correctly', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update(['interior_sundries_cost_per_hour' => 3.30]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 10.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $sundriesBreakdown = $component->get('sundriesBreakdown');

        expect($sundriesBreakdown['labor_hours'])->toBe(10.0);
        expect($sundriesBreakdown['cost_per_hour'])->toBe(3.30);
        expect($sundriesBreakdown['total_cost'])->toBe(33.00);
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
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 16.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $painterPay = $component->get('painterPayBreakdown');

        expect($painterPay['base_hourly_wage'])->toBe(21.00);
        expect($painterPay['payroll_burden_multiplier'])->toBe(1.35);
        expect($painterPay['payroll_burden_percent'])->toBe(35.0);
        expect($painterPay['effective_hourly_rate'])->toBe(28.35);
        expect($painterPay['labor_hours'])->toBe(16.0);
        expect($painterPay['cleanup_hours'])->toBe(1.0);
        expect($painterPay['total_hours'])->toBe(17.0);
        expect($painterPay['painter_earns_base'])->toBe(357.00);
        expect($painterPay['company_pays_loaded'])->toBe(481.95);
    });

    it('generates crew schedule grid with 1-4 painters', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update([
            'interior_hourly_wage' => 21.00,
            'interior_cleanup_hours_ratio' => 16.00,
        ]);

        $category = ProductionRateCategory::factory()->create();
        $rate = ProductionRate::factory()->create(['category_id' => $category->id]);

        $estimate = Estimate::factory()->create(['project_type' => 'interior']);
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'labor_hours' => 16.0,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $crewGrid = $component->get('crewScheduleGrid');

        expect($crewGrid['grid'])->toHaveCount(4);
        expect($crewGrid['total_hours'])->toBe(17.0);

        // 1 painter: 17 hours / 8 hours per day = 2.1 days
        expect($crewGrid['grid'][0]['painters'])->toBe(1);
        expect($crewGrid['grid'][0]['days_needed'][8])->toBe(2.1);

        // 2 painters: 17 / 2 = 8.5 hours per painter / 8 = 1.1 days
        expect($crewGrid['grid'][1]['painters'])->toBe(2);
        expect($crewGrid['grid'][1]['days_needed'][8])->toBe(1.1);

        // Total job pay stays the same regardless of crew size
        expect($crewGrid['grid'][0]['total_job_pay'])->toBe($crewGrid['grid'][3]['total_job_pay']);
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
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'paint_gallons' => 3,
            'is_included' => true,
        ]);

        $component = Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $materialsWithCosts = $component->get('materialsWithCosts');

        expect($materialsWithCosts['items'])->toHaveCount(1);
        expect($materialsWithCosts['items'][0]['brand'])->toBe('Sherwin-Williams');
        expect($materialsWithCosts['items'][0]['price_per_gallon'])->toBe(65.00);
        expect($materialsWithCosts['items'][0]['gallons'])->toBe(3.0);
        expect($materialsWithCosts['items'][0]['paint_cost'])->toBe(195.00);
    });
});

describe('Job Painter Breakdown Materials', function () {
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
        $job = Job::factory()->for($estimate, 'estimate')->create();

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
            ->test('jobs.painter-breakdown', ['job' => $job]);

        $materials = $component->get('materialsNeeded');

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
        $job = Job::factory()->for($estimate, 'estimate')->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);

        EstimateAreaSurface::factory()->create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'paint_product_id' => $product->id,
            'paint_gallons' => 2,
            'is_included' => true,
        ]);

        Livewire::actingAs($user)
            ->test('jobs.painter-breakdown', ['job' => $job])
            ->assertSee('Materials Needed')
            ->assertSee('PPG Manor Hall')
            ->assertSee('2 gallons');
    });
});
