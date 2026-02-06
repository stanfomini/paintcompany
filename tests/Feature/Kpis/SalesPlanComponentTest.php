<?php

use App\Models\CompanySetting;
use App\Models\SalesPlanGoal;
use App\Models\SalesPlanWeek;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    // Create permissions
    foreach (['view kpis', 'edit kpi targets', 'enter kpi actuals', 'manage kpis'] as $perm) {
        Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
    }

    // Create roles
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());

    // Create company settings
    $this->settings = CompanySetting::getInstance();
});

it('requires authentication', function () {
    $this->get(route('kpis.sales'))
        ->assertRedirect(route('login'));
});

it('requires internal user', function () {
    $customer = User::factory()->customer()->create();

    // Customer type mismatch results in redirect
    $this->actingAs($customer)
        ->get(route('kpis.sales'))
        ->assertRedirect();
});

it('legacy kpis/sales URL redirects to business plan', function () {
    $user = User::factory()->internal()->create();

    // Legacy /kpis/sales route redirects to business plan page (permission check is on the target)
    $this->actingAs($user)
        ->get(route('kpis.sales'))
        ->assertRedirect(route('business-plan.index', ['tab' => 'sales-plan']));
});

it('legacy kpis/sales URL redirects for authorized users', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    // Legacy /kpis/sales redirects to business plan
    $this->actingAs($user)
        ->get(route('kpis.sales'))
        ->assertRedirect(route('business-plan.index', ['tab' => 'sales-plan']));
});

it('displays annual goals', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'annual_revenue_goal' => 1500000,
        'avg_job_size' => 5000,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->assertSee('1,500,000')
        ->assertSee('5,000');
});

it('displays weekly data', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'week_number' => 1,
        'leads_plan' => 10,
        'leads_actual' => 12,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->assertSee('Week 1')
        ->assertSee('10')
        ->assertSee('12');
});

it('can change year', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->set('year', 2025)
        ->assertSet('year', 2025);
});

it('can open goal modal', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'annual_revenue_goal' => 1500000,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->call('openGoalModal')
        ->assertSet('showGoalModal', true)
        ->assertSet('goalAnnualRevenue', 1500000.0);
});

it('can save goal', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'annual_revenue_goal' => 1500000,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->call('openGoalModal')
        ->set('goalAnnualRevenue', 2000000)
        ->set('goalAvgJobSize', 6000)
        ->set('goalLeadConversion', 70)
        ->set('goalCloseRate', 60)
        ->call('saveGoal')
        ->assertSet('showGoalModal', false);

    $goal = SalesPlanGoal::where('company_setting_id', $this->settings->id)
        ->where('year', now()->year)
        ->first();

    expect($goal->annual_revenue_goal)->toBe('2000000.00');
});

it('can open week modal', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'week_number' => 5,
        'leads_plan' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->call('openWeekModal', 5)
        ->assertSet('showWeekModal', true)
        ->assertSet('editWeekNumber', 5)
        ->assertSet('leadsPlanned', 10);
});

it('can save week plan', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'week_number' => 5,
        'leads_plan' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->call('openWeekModal', 5)
        ->set('leadsPlanned', 15)
        ->set('estimatesPlanned', 8)
        ->set('jobsPlanned', 4)
        ->set('revenuePlanned', 20000)
        ->call('saveWeekPlan')
        ->assertSet('showWeekModal', false);

    $week = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', now()->year)
        ->where('week_number', 5)
        ->first();

    expect($week->leads_plan)->toBe(15);
});

it('can sync current week', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    $week = SalesPlanWeek::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'week_number' => now()->weekOfYear,
        'leads_actual' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->call('syncCurrentWeek');

    $week->refresh();
    expect($week->synced_at)->not->toBeNull();
});

it('can populate weeks from goals', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    SalesPlanGoal::factory()->create([
        'company_setting_id' => $this->settings->id,
        'year' => now()->year,
        'annual_revenue_goal' => 520000,
        'avg_job_size' => 5200,
        'lead_conversion_rate' => 100,
        'estimate_close_rate' => 100,
    ]);

    Livewire::actingAs($user)
        ->test('kpis.sales-plan')
        ->call('populateFromGoals');

    $weeks = SalesPlanWeek::where('company_setting_id', $this->settings->id)
        ->where('year', now()->year)
        ->get();

    expect($weeks)->toHaveCount(52);
});
