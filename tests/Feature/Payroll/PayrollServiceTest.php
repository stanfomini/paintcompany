<?php

use App\Models\CompanySetting;
use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(PayrollService::class);
});

it('creates a pay period', function () {
    $period = $this->service->createPayPeriod('2026-01-01', '2026-01-14', 'biweekly');

    expect($period)
        ->toBeInstanceOf(PayPeriod::class)
        ->status->toBe('open')
        ->type->toBe('biweekly');

    $this->assertDatabaseHas('pay_periods', [
        'id' => $period->id,
        'type' => 'biweekly',
        'status' => 'open',
    ]);
});

it('generates a pay stub for an hourly employee', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period = PayPeriod::factory()->biweekly()->create();

    $stub = $this->service->generatePayStub($employee, $period, 80, 5, 0);

    expect($stub)
        ->toBeInstanceOf(PayStub::class)
        ->hours_regular->toBe('80.00')
        ->hours_overtime->toBe('5.00')
        ->status->toBe('draft');

    // Gross should be: (80 * 25) + (5 * 37.50) = 2000 + 187.50 = 2187.50
    expect((float) $stub->gross_pay)->toBe(2187.50);
    expect((float) $stub->net_pay)->toBeGreaterThan(0)
        ->toBeLessThan((float) $stub->gross_pay);
});

it('generates a pay stub for a salaried employee', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->salaried()
        ->withW4()
        ->create([
            'pay_rate' => 52000.00,
        ]);

    $period = PayPeriod::factory()->biweekly()->create();

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Salaried: $52,000 / 26 pay periods = $2,000 per period
    expect((float) $stub->gross_pay)->toBe(2000.00);
    expect((float) $stub->net_pay)->toBeGreaterThan(0);
});

it('applies pretax deductions before tax calculation', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

    // Create a pretax deduction
    EmployeeDeduction::factory()->create([
        'employee_profile_id' => $employee->id,
        'type' => 'health_insurance',
        'description' => 'Health Insurance',
        'amount' => 100,
        'is_percentage' => false,
        'is_pretax' => true,
        'is_active' => true,
    ]);

    $period = PayPeriod::factory()->biweekly()->create();

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Should have pretax deductions in the JSON
    expect($stub->pretax_deductions)->toBeArray()
        ->toHaveCount(1);

    expect($stub->pretax_deductions[0])
        ->toHaveKey('description', 'Health Insurance')
        ->toHaveKey('amount', 100.0);

    // Net pay should reflect the deduction
    expect((float) $stub->total_deductions)->toBeGreaterThan(100);
});

it('applies percentage-based deductions correctly', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    // 6% 401k contribution
    EmployeeDeduction::factory()->percentage()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 6,
    ]);

    $period = PayPeriod::factory()->biweekly()->create();

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Gross = 80 * 25 = 2000
    // 6% of 2000 = 120
    expect($stub->pretax_deductions[0]['amount'])->toEqual(120.00);
});

it('calculates ytd totals from previous stubs', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    // Create a previous pay period and stub in the same year
    $prevPeriod = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-14',
    ]);

    PayStub::factory()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $prevPeriod->id,
        'gross_pay' => 2000,
        'federal_tax' => 200,
        'state_tax' => 88,
        'social_security' => 124,
        'medicare' => 29,
        'additional_medicare' => 0,
    ]);

    // Now create a new period
    $newPeriod = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-28',
    ]);

    $stub = $this->service->generatePayStub($employee, $newPeriod, 80, 0, 0);

    // YTD should include previous stub + current
    expect((float) $stub->ytd_gross)->toBeGreaterThan(2000);
});

it('finalizes payroll for a period', function () {
    CompanySetting::getInstance();

    $user = User::factory()->internal()->create();
    $period = PayPeriod::factory()->create(['status' => 'processing']);

    $employee = EmployeeProfile::factory()->withW4()->create([
        'pay_rate' => 25.00,
        'pay_type' => 'hourly',
    ]);

    // Create a draft stub
    PayStub::factory()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $period->id,
        'status' => 'draft',
    ]);

    $this->service->finalizePayroll($period, $user);

    $period->refresh();
    expect($period->status)->toBe('completed')
        ->and($period->processed_by)->toBe($user->id)
        ->and($period->processed_at)->not->toBeNull();

    // All stubs should be finalized
    $stubs = $period->payStubs()->get();
    foreach ($stubs as $stub) {
        expect($stub->status)->toBe('finalized')
            ->and($stub->check_number)->not->toBeNull();
    }
});

it('gets next check number', function () {
    // No stubs yet
    expect($this->service->getNextCheckNumber())->toBe('1001');

    // Add a stub with check number
    PayStub::factory()->finalized()->create(['check_number' => '1005']);

    expect($this->service->getNextCheckNumber())->toBe('1006');
});

// ──────────────────────────────────────────────────
// calculateHoursFromTimeEntries Tests
// ──────────────────────────────────────────────────

it('calculates hours from approved time entries for a pay period', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Create approved time entries within the period
    \App\Models\TimeEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'clock_in' => '2026-02-03 08:00:00',
        'clock_out' => '2026-02-03 16:30:00',
        'total_hours' => 8.50,
        'overtime_hours' => 0,
    ]);

    \App\Models\TimeEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'clock_in' => '2026-02-04 08:00:00',
        'clock_out' => '2026-02-04 18:00:00',
        'total_hours' => 10.00,
        'overtime_hours' => 2.00,
    ]);

    $result = $this->service->calculateHoursFromTimeEntries($period);

    expect($result)->toHaveKey($employee->id);
    expect($result[$employee->id]['regular'])->toBe(16.50); // 8.50 + (10.00 - 2.00)
    expect($result[$employee->id]['overtime'])->toBe(2.00);
    expect($result[$employee->id]['pto'])->toBe(0.0);
});

it('ignores non-approved time entries', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Create a pending entry (not approved)
    \App\Models\TimeEntry::factory()->completed()->create([
        'employee_profile_id' => $employee->id,
        'clock_in' => '2026-02-03 08:00:00',
        'clock_out' => '2026-02-03 16:00:00',
        'total_hours' => 8.00,
        'status' => 'pending',
    ]);

    $result = $this->service->calculateHoursFromTimeEntries($period);

    expect($result)->toBeEmpty();
});

it('ignores time entries outside the pay period', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Entry outside the period
    \App\Models\TimeEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'clock_in' => '2026-01-28 08:00:00',
        'clock_out' => '2026-01-28 16:00:00',
        'total_hours' => 8.00,
    ]);

    $result = $this->service->calculateHoursFromTimeEntries($period);

    expect($result)->toBeEmpty();
});

it('includes approved PTO hours in time entry import', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Approved PTO within the period
    \App\Models\PtoRequest::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'start_date' => '2026-02-05',
        'end_date' => '2026-02-06',
        'hours' => 16.0,
    ]);

    $result = $this->service->calculateHoursFromTimeEntries($period);

    expect($result)->toHaveKey($employee->id);
    expect($result[$employee->id]['pto'])->toBe(16.0);
    expect($result[$employee->id]['regular'])->toBe(0.0);
});

it('generates pay stub for commission-only employee', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->commissionOnly()
        ->withW4()
        ->create();

    $period = PayPeriod::factory()->biweekly()->create();

    $stub = $this->service->generatePayStub($employee, $period, 0, 0, 0);

    expect($stub)->toBeInstanceOf(PayStub::class);
    // Commission-only with no pending commissions: gross = 0
    expect((float) $stub->rate_regular)->toBe(0.00);
    expect((float) $stub->rate_overtime)->toBe(0.00);
    // Gross pay should only be commission amount (0 in this case, no pending commissions)
    expect((float) $stub->gross_pay)->toBe(0.00);
});

it('includes commission-only employees in payroll run', function () {
    CompanySetting::getInstance();

    // Create a commission-only employee (no pay_rate)
    $commissionEmp = EmployeeProfile::factory()
        ->commissionOnly()
        ->withW4()
        ->create();

    // Create a regular hourly employee
    $hourlyEmp = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period = PayPeriod::factory()->biweekly()->create();

    $stubs = $this->service->runPayroll($period, [
        $commissionEmp->id => ['regular' => 0, 'overtime' => 0, 'pto' => 0],
        $hourlyEmp->id => ['regular' => 40, 'overtime' => 0, 'pto' => 0],
    ]);

    // Both employees should have stubs
    expect($stubs)->toHaveCount(2);

    $commissionStub = collect($stubs)->first(fn ($s) => $s->employee_profile_id === $commissionEmp->id);
    $hourlyStub = collect($stubs)->first(fn ($s) => $s->employee_profile_id === $hourlyEmp->id);

    expect($commissionStub)->not->toBeNull();
    expect($hourlyStub)->not->toBeNull();
    expect((float) $hourlyStub->gross_pay)->toBeGreaterThan(0);
});

it('generates pay stub for salary plus commission employee', function () {
    CompanySetting::getInstance();

    $employee = EmployeeProfile::factory()
        ->salaryPlusCommission()
        ->withW4()
        ->create([
            'pay_rate' => 52000.00,
        ]);

    $period = PayPeriod::factory()->biweekly()->create();

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Salary: $52,000 / 26 = $2,000 per period
    expect((float) $stub->gross_pay)->toBe(2000.00);
    expect((float) $stub->net_pay)->toBeGreaterThan(0);
});

it('combines time entries and PTO for the same employee', function () {
    $employee = EmployeeProfile::factory()->create();
    $period = PayPeriod::factory()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    \App\Models\TimeEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'clock_in' => '2026-02-03 08:00:00',
        'clock_out' => '2026-02-03 16:00:00',
        'total_hours' => 8.00,
        'overtime_hours' => 0,
    ]);

    \App\Models\PtoRequest::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'start_date' => '2026-02-10',
        'end_date' => '2026-02-10',
        'hours' => 8.0,
    ]);

    $result = $this->service->calculateHoursFromTimeEntries($period);

    expect($result[$employee->id]['regular'])->toBe(8.0);
    expect($result[$employee->id]['overtime'])->toBe(0.0);
    expect($result[$employee->id]['pto'])->toBe(8.0);
});
