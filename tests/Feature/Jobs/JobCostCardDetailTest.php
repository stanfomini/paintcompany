<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'financials.view_cost_breakdown', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'jobs.view', 'guard_name' => 'web']);
    $ownerRole = Role::firstOrCreate(['name' => 'owner', 'guard_name' => 'web']);
    $ownerRole->syncPermissions(Permission::all());

    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();
});

// ─── Helper to create a job with estimate and area/surface data ───
function createJobWithEstimateData(array $overrides = []): array
{
    $settings = CompanySetting::create(array_merge([
        'company_name' => 'Test Painting Co',
        'interior_hourly_wage' => 25.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_margin_percent' => 50.00,
        'exterior_margin_percent' => 41.00,
        'interior_cleanup_ratio' => 6.67,
        'exterior_cleanup_ratio' => 5.00,
        'interior_sundries_percent' => 1.50,
        'exterior_sundries_percent' => 2.00,
        'material_tax_rate' => 7.5,
        'labor_tax_rate' => 0,
        'marketing_percent' => 5,
        'sales_percent' => 10,
        'production_management_percent' => 5,
        'overhead_percent' => 15,
        'net_profit_margin_percent' => 20,
    ], $overrides['settings'] ?? []));

    CompanySetting::clearInstanceCache();

    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
        'subtotal' => $overrides['subtotal'] ?? 5000,
        'project_type' => $overrides['project_type'] ?? 'interior',
    ]);

    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'estimate_id' => $estimate->id,
    ]);

    // Create area with surfaces for breakdown service
    $area = EstimateArea::factory()->forEstimate($estimate)->create([
        'name' => $overrides['area_name'] ?? 'Living Room',
        'scope_type' => $overrides['scope_type'] ?? 'interior',
    ]);

    $surface = EstimateAreaSurface::factory()->wall()->forArea($area)->create([
        'is_included' => true,
        'quantity' => 400,
        'coats' => 2,
        'production_rate_value' => 200,
        'hourly_labor_rate' => 33.75, // 25 * 1.35
        'material_cost_per_unit' => 0.15,
        'labor_hours' => 4.0,
        'labor_cost' => 135.0,
        'material_cost' => 120.0,
        'total' => 255.0,
    ]);

    return compact('settings', 'customer', 'deal', 'estimate', 'job', 'area', 'surface');
}

// ─── Access Control ───

it('route accessible with correct permissions', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'total_cogs' => 2500,
        'labor_hours' => 20,
        'total_hours' => 25,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200);
});

it('route returns 403 without financials permission', function () {
    $viewPermission = Permission::firstOrCreate(['name' => 'jobs.view', 'guard_name' => 'web']);
    $viewRole = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => 'web']);
    $viewRole->syncPermissions([$viewPermission]);

    $user = User::factory()->internal()->create();
    $user->assignRole('viewer');

    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create();

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertForbidden();
});

// ─── Tab 1: Projected Costs ───

it('component renders projected COGS breakdown', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'total_cogs' => 3500,
        'labor_cost' => 2000,
        'material_cost' => 800,
        'cleanup_cost' => 300,
        'sundries_cost' => 100,
        'price' => 8000,
        'labor_hours' => 20,
        'cleanup_hours' => 3,
        'total_hours' => 23,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Cost Card')
        ->assertSee('COGS');
});

it('component renders COGS decomposition with labeled rows', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 8000,
        'labor_cost' => 2000,
        'cleanup_cost' => 300,
        'sundries_cost' => 100,
        'material_cost' => 800,
        'total_cogs' => 3500,
        'labor_hours' => 20,
        'cleanup_hours' => 3,
        'total_hours' => 23,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Labor')
        ->assertSee('Cleanup')
        ->assertSee('Sundries')
        ->assertSee('Materials');
});

it('component shows price and profit summary cards', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 10000,
        'total_cogs' => 4000,
        'labor_cost' => 2500,
        'material_cost' => 800,
        'cleanup_cost' => 400,
        'sundries_cost' => 100,
        'labor_hours' => 30,
        'cleanup_hours' => 4.5,
        'total_hours' => 34.5,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Customer Price')
        ->assertSee('Gross Profit')
        ->assertSee('Net Profit');
});

it('component renders hours inline in ledger breakdown', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 6000,
        'total_cogs' => 2500,
        'labor_hours' => 15,
        'cleanup_hours' => 2.25,
        'total_hours' => 17.25,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Financial Ledger')
        ->assertSee('Labor')
        ->assertSee('Cleanup')
        ->assertSee('hrs');
});

it('component shows allocations in live breakdown', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 10000,
        'total_cogs' => 4000,
        'allocation_total' => 1400,
    ]);

    // The projected tab now uses the live breakdown service, which calculates
    // allocations from CompanySetting (legacy format: marketing, sales, prod mgmt)
    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Business Allocations')
        ->assertSee('Marketing')
        ->assertSee('Sales')
        ->assertSee('Production Mgmt');
});

// ─── Tab 2: Actuals & Variance ───

it('variance section appears when actuals present', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->withActuals()->create([
        'price' => 7000,
        'total_cogs' => 3000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->set('activeTab', 'actuals')
        ->assertSee('Actual');
});

it('actuals tab shows the enter actuals form', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->set('activeTab', 'actuals')
        ->assertSee('Enter Actual Costs')
        ->assertSee('Material Cost')
        ->assertSee('Labor Cost')
        ->assertSee('Save Actuals');
});

it('actuals tab shows variance table when actuals are entered', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->withActuals()->create([
        'price' => 8000,
        'total_cogs' => 3500,
        'labor_cost' => 2000,
        'cleanup_cost' => 300,
        'material_cost' => 800,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->set('activeTab', 'actuals')
        ->assertSee('Projected vs Actual')
        ->assertSee('Variance');
});

// ─── Tab 3: Payments ───

it('component shows payment summary', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 6000,
        'total_invoiced' => 6000,
        'total_collected' => 4000,
        'balance_due' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->set('activeTab', 'payments')
        ->assertSee('6,000.00') // invoiced
        ->assertSee('4,000.00') // collected
        ->assertSee('2,000.00'); // balance
});

it('payments tab shows collection percentage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 10000,
        'total_invoiced' => 10000,
        'total_collected' => 7500,
        'balance_due' => 2500,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->set('activeTab', 'payments')
        ->assertSee('Total Invoiced')
        ->assertSee('Total Collected')
        ->assertSee('Balance Due')
        ->assertSee('Collection %');
});

// ─── Edge Cases ───

it('component handles job without cost card gracefully', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    // No JobCostCard created - projected tab still works via live breakdown
    // But actuals/payments tabs show "No Cost Card" message
    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Financial Ledger') // Projected tab uses live breakdown
        ->set('activeTab', 'actuals')
        ->assertSee('No Cost Card');
});

it('component shows tab buttons for projected actuals and payments', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Projected Costs')
        ->assertSee('Actuals')
        ->assertSee('Payments');
});

it('component switches between tabs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
        'total_invoiced' => 5000,
        'total_collected' => 3000,
        'balance_due' => 2000,
    ]);

    $component = Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200);

    // Default tab is projected
    $component->assertSet('activeTab', 'projected');

    // Switch to actuals
    $component->set('activeTab', 'actuals')
        ->assertSee('Enter Actual Costs');

    // Switch to payments
    $component->set('activeTab', 'payments')
        ->assertSee('Total Invoiced');
});

// ─── Internal Only Warning ───

it('shows internal only warning banner', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Internal Only')
        ->assertSee('Never share with customers');
});

// ─── Export Links ───

it('shows export csv and pdf links when estimate is linked', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Export CSV')
        ->assertSee('Export PDF')
        ->assertSee('Print');
});

// ─── New Features: Comparison, What-If, Tier ───

it('shows settings comparison tab when estimate has snapshot', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    // Save a settings snapshot on the estimate with different values
    $data['estimate']->update([
        'settings_snapshot' => [
            'scope' => 'interior',
            'hourly_wage' => 22.00,
            'payroll_burden_multiplier' => 1.30,
            'margin_percent' => 45.0,
            'cleanup_hours_ratio' => 6.67,
            'sundries_cost_per_hour' => 1.50,
            'material_tax_rate' => 7.5,
            'net_profit_margin_percent' => 20.0,
            'hourly_rate_override' => null,
        ],
    ]);

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('Settings Comparison')
        ->call('setActiveTab', 'comparison')
        ->assertSet('activeTab', 'comparison')
        ->assertSee('Setting');
});

it('does not show comparison tab when estimate has no snapshot', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    // Ensure no snapshot
    $data['estimate']->update(['settings_snapshot' => null]);

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertDontSee('Settings Comparison');
});

it('what-if margin slider is present in projected tab', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee('What-If: Margin Rate');
});

it('what-if margin can be updated', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->call('updateWhatIfMargin', 40.0)
        ->assertSet('whatIfMargin', 40.0);
});

it('what-if margin is clamped between 0 and 100', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    $component = Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']]);

    $component->call('updateWhatIfMargin', 150.0)
        ->assertSet('whatIfMargin', 100.0);

    $component->call('updateWhatIfMargin', -10.0)
        ->assertSet('whatIfMargin', 0.0);
});

it('toggleWhatIf toggles the showWhatIf state', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertSet('showWhatIf', false)
        ->call('toggleWhatIf')
        ->assertSet('showWhatIf', true)
        ->call('toggleWhatIf')
        ->assertSet('showWhatIf', false);
});

it('setActiveTab method works for all tabs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertSet('activeTab', 'projected')
        ->call('setActiveTab', 'actuals')
        ->assertSet('activeTab', 'actuals')
        ->call('setActiveTab', 'payments')
        ->assertSet('activeTab', 'payments')
        ->call('setActiveTab', 'projected')
        ->assertSet('activeTab', 'projected');
});

it('shows estimate number in header', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $data = createJobWithEstimateData();

    JobCostCard::factory()->forJob($data['job'])->create([
        'price' => 5000,
        'total_cogs' => 2000,
    ]);

    Livewire::actingAs($user)
        ->test('jobs.cost-card', ['job' => $data['job']])
        ->assertStatus(200)
        ->assertSee($data['estimate']->estimate_number);
});
