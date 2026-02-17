<?php

use App\Models\ContractTemplate;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\User;
use Livewire\Livewire;

// ── My Pay Stubs ──────────────────────────────────────────────────

it('renders my pay stubs page for employee', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('my.pay-stubs'))
        ->assertOk()
        ->assertSee('My Pay Stubs');
});

it('shows pay stubs belonging to the employee', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $period = PayPeriod::factory()->create();

    $stub = PayStub::factory()->finalized()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $period->id,
        'gross_pay' => 1500.00,
        'net_pay' => 1200.00,
        'check_number' => '4567',
    ]);

    Livewire::actingAs($user)
        ->test('payroll.my-pay-stubs')
        ->assertSee('$1,500.00')
        ->assertSee('$1,200.00')
        ->assertSee('4567');
});

it('does not show other employees pay stubs', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $otherEmployee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create();

    PayStub::factory()->finalized()->create([
        'employee_profile_id' => $otherEmployee->id,
        'pay_period_id' => $period->id,
        'check_number' => '9999',
    ]);

    Livewire::actingAs($user)
        ->test('payroll.my-pay-stubs')
        ->assertDontSee('9999');
});

it('shows ytd totals for current year', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $currentYearPeriod = PayPeriod::factory()->create([
        'start_date' => now()->startOfYear(),
        'end_date' => now()->startOfYear()->addDays(13),
    ]);

    PayStub::factory()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $currentYearPeriod->id,
        'gross_pay' => 2000.00,
        'total_deductions' => 500.00,
        'net_pay' => 1500.00,
    ]);

    PayStub::factory()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $currentYearPeriod->id,
        'gross_pay' => 1800.00,
        'total_deductions' => 400.00,
        'net_pay' => 1400.00,
    ]);

    Livewire::actingAs($user)
        ->test('payroll.my-pay-stubs')
        ->assertSee('$3,800.00')
        ->assertSee('$900.00')
        ->assertSee('$2,900.00');
});

it('denies my pay stubs page to users without employee profile', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('my.pay-stubs'))
        ->assertForbidden();
});

// ── My Contracts ──────────────────────────────────────────────────

it('renders my contracts page for employee', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('my.contracts'))
        ->assertOk()
        ->assertSee('My Contracts');
});

it('shows contracts belonging to the employee', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
    $template = ContractTemplate::factory()->create();

    $contract = EmployeeContract::factory()->sent()->create([
        'employee_profile_id' => $employee->id,
        'contract_template_id' => $template->id,
        'title' => 'Employment Agreement 2026',
    ]);

    Livewire::actingAs($user)
        ->test('contracts.my-contracts')
        ->assertSee('Employment Agreement 2026')
        ->assertSee('Sent');
});

it('does not show other employees contracts', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $otherEmployee = EmployeeProfile::factory()->create();
    EmployeeContract::factory()->create([
        'employee_profile_id' => $otherEmployee->id,
        'title' => 'Secret Other Contract',
    ]);

    Livewire::actingAs($user)
        ->test('contracts.my-contracts')
        ->assertDontSee('Secret Other Contract');
});

it('shows pending contract alert when unsigned contracts exist', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    EmployeeContract::factory()->sent()->create([
        'employee_profile_id' => $employee->id,
        'title' => 'Unsigned Contract',
    ]);

    Livewire::actingAs($user)
        ->test('contracts.my-contracts')
        ->assertSee('awaiting your signature');
});

it('does not show pending alert when all contracts are signed', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);

    EmployeeContract::factory()->signed()->create([
        'employee_profile_id' => $employee->id,
    ]);

    Livewire::actingAs($user)
        ->test('contracts.my-contracts')
        ->assertDontSee('awaiting your signature');
});

it('denies my contracts page to users without employee profile', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('my.contracts'))
        ->assertForbidden();
});
