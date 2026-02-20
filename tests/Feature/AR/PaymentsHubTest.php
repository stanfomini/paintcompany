<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

it('renders payments hub page', function () {
    $this->get(route('payments.index'))
        ->assertSuccessful()
        ->assertSeeLivewire('accounting.payments-hub');
});

it('shows payables tab by default', function () {
    Livewire::test('accounting.payments-hub')
        ->assertSee('All Payables');
});

it('filters payables by status', function () {
    $job = Job::factory()->create();
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'status' => 'pending',
        'amount' => 100.00,
        'payee_name' => 'Pending Payee',
    ]);
    JobPayable::factory()->paid()->create([
        'job_id' => $job->id,
        'amount' => 200.00,
        'payee_name' => 'Paid Payee',
    ]);

    Livewire::test('accounting.payments-hub', ['statusFilter' => 'pending'])
        ->assertSee('Pending Payee')
        ->assertDontSee('Paid Payee');
});

it('filters payables by type', function () {
    $job = Job::factory()->create();
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'payee_type' => 'crew',
        'payee_name' => 'Crew Alpha',
    ]);
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'payee_type' => 'vendor',
        'payee_name' => 'Vendor Beta',
    ]);

    Livewire::test('accounting.payments-hub', ['payeeTypeFilter' => 'crew'])
        ->assertSee('Crew Alpha')
        ->assertDontSee('Vendor Beta');
});

it('searches payables by payee name', function () {
    $job = Job::factory()->create();
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'payee_name' => 'Alice Smith',
    ]);
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'payee_name' => 'Bob Jones',
    ]);

    Livewire::test('accounting.payments-hub')
        ->set('search', 'Alice')
        ->assertSee('Alice Smith')
        ->assertDontSee('Bob Jones');
});

it('approves selected payables in bulk', function () {
    $job = Job::factory()->create();
    $p1 = JobPayable::factory()->create(['job_id' => $job->id, 'status' => 'pending']);
    $p2 = JobPayable::factory()->create(['job_id' => $job->id, 'status' => 'pending']);

    Livewire::test('accounting.payments-hub')
        ->set('selectedPayables', [$p1->id, $p2->id])
        ->call('approveSelected');

    expect($p1->fresh()->status)->toBe('approved');
    expect($p2->fresh()->status)->toBe('approved');
});

it('marks selected approved payables as paid', function () {
    $job = Job::factory()->create();
    $p1 = JobPayable::factory()->approved()->create(['job_id' => $job->id]);
    $p2 = JobPayable::factory()->approved()->create(['job_id' => $job->id]);

    Livewire::test('accounting.payments-hub')
        ->set('selectedPayables', [$p1->id, $p2->id])
        ->call('markSelectedPaid');

    expect($p1->fresh()->status)->toBe('paid');
    expect($p2->fresh()->status)->toBe('paid');
});

it('shows commissions tab with entries', function () {
    $employee = EmployeeProfile::factory()->create();
    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
        'amount' => 500.00,
    ]);

    Livewire::test('accounting.payments-hub', ['tab' => 'commissions'])
        ->assertSee('Commissions')
        ->assertSee('$500.00');
});

it('approves single commission inline', function () {
    $employee = EmployeeProfile::factory()->create();
    $entry = CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
        'amount' => 500.00,
    ]);

    Livewire::test('accounting.payments-hub', ['tab' => 'commissions'])
        ->call('approveCommission', $entry->id);

    expect($entry->fresh()->status)->toBe('approved');
});

it('approves selected commissions in bulk', function () {
    $employee = EmployeeProfile::factory()->create();
    $c1 = CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
    ]);
    $c2 = CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
    ]);

    Livewire::test('accounting.payments-hub', ['tab' => 'commissions'])
        ->set('selectedCommissions', [$c1->id, $c2->id])
        ->call('approveSelectedCommissions');

    expect($c1->fresh()->status)->toBe('approved');
    expect($c2->fresh()->status)->toBe('approved');
});

it('shows 1099 tab with crew totals', function () {
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create(['name' => 'Mike Crew']);
    JobPayable::factory()->paid()->create([
        'job_id' => $job->id,
        'payee_type' => 'crew',
        'payee_user_id' => $crew->id,
        'payee_name' => 'Mike Crew',
        'amount' => 750.00,
    ]);

    Livewire::test('accounting.payments-hub', ['tab' => '1099'])
        ->assertSee('Mike Crew')
        ->assertSee('$750.00');
});

it('shows w9 missing badge for crew without w9', function () {
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create(['name' => 'No W9 Crew']);
    $crew->crewProfile->update(['w9_received_date' => null]);

    JobPayable::factory()->paid()->create([
        'job_id' => $job->id,
        'payee_type' => 'crew',
        'payee_user_id' => $crew->id,
        'payee_name' => 'No W9 Crew',
        'amount' => 700.00,
    ]);

    Livewire::test('accounting.payments-hub', ['tab' => '1099'])
        ->assertSee('No W9 Crew')
        ->assertSee('Missing');
});

it('exports 1099 CSV', function () {
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create(['name' => 'CSV Crew']);
    JobPayable::factory()->paid()->create([
        'job_id' => $job->id,
        'payee_type' => 'crew',
        'payee_user_id' => $crew->id,
        'payee_name' => 'CSV Crew',
        'amount' => 1000.00,
    ]);

    $response = $this->get(route('payments.1099-export', ['year' => now()->year]));
    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    expect($response->getContent())->toContain('CSV Crew');
    expect($response->getContent())->toContain('Yes'); // >= $600
});

it('denies access to unauthorized users', function () {
    $viewer = User::factory()->internal()->create();
    $this->actingAs($viewer);

    $this->get(route('payments.index'))
        ->assertForbidden();
});

it('denies bulk approve to users without ar.manage', function () {
    $viewOnly = User::factory()->internal()->create();
    // Give only ar.view, not ar.manage
    $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
    $registrar->forgetCachedPermissions();
    if (\Spatie\Permission\Models\Permission::where('name', 'ar.view')->doesntExist()) {
        \Spatie\Permission\Models\Permission::create(['name' => 'ar.view', 'guard_name' => 'web']);
    }
    $viewOnly->givePermissionTo('ar.view');

    $job = Job::factory()->create();
    $payable = JobPayable::factory()->create(['job_id' => $job->id, 'status' => 'pending']);

    Livewire::actingAs($viewOnly)
        ->test('accounting.payments-hub')
        ->set('selectedPayables', [$payable->id])
        ->call('approveSelected')
        ->assertForbidden();
});

it('shows summary totals correctly', function () {
    $job = Job::factory()->create();
    JobPayable::factory()->create([
        'job_id' => $job->id,
        'status' => 'pending',
        'amount' => 300.00,
    ]);
    JobPayable::factory()->paid()->create([
        'job_id' => $job->id,
        'amount' => 500.00,
    ]);

    Livewire::test('accounting.payments-hub')
        ->assertSee('$300.00')  // Outstanding includes pending
        ->assertSee('$500.00'); // Paid this month
});

it('switches tabs correctly', function () {
    Livewire::test('accounting.payments-hub')
        ->assertSee('All Payables')
        ->set('tab', 'commissions')
        ->assertSee('Commissions')
        ->set('tab', '1099')
        ->assertSee('1099 Tracker')
        ->set('tab', 'payroll')
        ->assertSee('Payroll');
});

it('filters commissions by status', function () {
    $employee = EmployeeProfile::factory()->create();
    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'status' => 'pending',
        'amount' => 100.00,
    ]);
    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 200.00,
    ]);

    Livewire::test('accounting.payments-hub', ['tab' => 'commissions', 'commissionStatusFilter' => 'pending'])
        ->assertSee('$100.00')
        ->assertDontSee('$200.00');
});
