<?php

use App\Models\CommissionEntry;
use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::getInstance();
    $this->service = app(PayrollService::class);
});

it('includes approved commissions in pay stub', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->commissioned()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Create approved commission entries
    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'commission_type' => 'revenue_percentage',
        'basis_amount' => 10000,
        'rate' => 0.05,
        'amount' => 500.00,
        'source_label' => 'Estimate: #EST-001',
        'earned_date' => '2026-02-05',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'commission_type' => 'flat',
        'basis_amount' => 0,
        'rate' => 0,
        'amount' => 200.00,
        'source_label' => 'Lead Commission',
        'earned_date' => '2026-02-10',
    ]);

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Commission amount should be 500 + 200 = 700
    expect((float) $stub->commission_amount)->toBe(700.00);

    // Commission details should list both entries
    expect($stub->commission_details)->toBeArray()->toHaveCount(2);
    expect($stub->commission_details[0])->toHaveKey('source_label', 'Estimate: #EST-001');
    expect($stub->commission_details[0])->toHaveKey('amount', 500.00);
    expect($stub->commission_details[1])->toHaveKey('source_label', 'Lead Commission');
    expect($stub->commission_details[1])->toHaveKey('amount', 200.00);
});

it('adds commission amount to gross pay', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 500.00,
        'earned_date' => '2026-02-05',
    ]);

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Gross = (80 * 25) + 500 commission = 2500
    expect((float) $stub->gross_pay)->toBe(2500.00);
});

it('marks commission entries as paid after stub generation', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    $entry1 = CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 300.00,
        'earned_date' => '2026-02-05',
    ]);

    $entry2 = CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 200.00,
        'earned_date' => '2026-02-10',
    ]);

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Both entries should be marked as paid
    $entry1->refresh();
    $entry2->refresh();

    expect($entry1->status)->toBe('paid');
    expect($entry1->pay_stub_id)->toBe($stub->id);
    expect($entry1->pay_period_id)->toBe($period->id);

    expect($entry2->status)->toBe('paid');
    expect($entry2->pay_stub_id)->toBe($stub->id);
    expect($entry2->pay_period_id)->toBe($period->id);
});

it('applies supplemental tax method with flat 22% on commission portion', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
            'commission_tax_method' => 'supplemental',
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 1000.00,
        'earned_date' => '2026-02-05',
    ]);

    $stubWithSupplemental = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Now create same scenario with aggregate method for comparison
    $employeeAgg = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
            'commission_tax_method' => 'aggregate',
        ]);

    $period2 = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employeeAgg->id,
        'amount' => 1000.00,
        'earned_date' => '2026-02-05',
    ]);

    $stubWithAggregate = $this->service->generatePayStub($employeeAgg, $period2, 80, 0, 0);

    // Both should have the same gross pay
    expect((float) $stubWithSupplemental->gross_pay)->toBe(3000.00);
    expect((float) $stubWithAggregate->gross_pay)->toBe(3000.00);

    // Federal tax should differ between methods
    // Supplemental: regular tax on $2000 + flat 22% on $1000 = regular + $220
    // Aggregate: tax on full $3000 as regular income
    expect((float) $stubWithSupplemental->federal_tax)->not->toBe((float) $stubWithAggregate->federal_tax);

    // The supplemental should include at least $220 (22% of $1000) in federal tax
    expect((float) $stubWithSupplemental->federal_tax)->toBeGreaterThanOrEqual(220.00);
});

it('accumulates ytd commission correctly', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    // Create a previous pay period and stub with commission
    $prevPeriod = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-14',
    ]);

    PayStub::factory()->create([
        'employee_profile_id' => $employee->id,
        'pay_period_id' => $prevPeriod->id,
        'gross_pay' => 2500,
        'commission_amount' => 500,
        'federal_tax' => 200,
        'state_tax' => 88,
        'social_security' => 124,
        'medicare' => 29,
        'additional_medicare' => 0,
        'ytd_commission' => 500,
    ]);

    // Now create a new period with more commission
    $newPeriod = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-01-15',
        'end_date' => '2026-01-28',
    ]);

    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 300.00,
        'earned_date' => '2026-01-20',
    ]);

    $stub = $this->service->generatePayStub($employee, $newPeriod, 80, 0, 0);

    // YTD commission should be previous (500) + current (300) = 800
    expect((float) $stub->ytd_commission)->toBe(800.00);
});

it('does not include pending commissions in pay stub', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Create a pending commission (not approved)
    CommissionEntry::factory()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 500.00,
        'status' => 'pending',
        'earned_date' => '2026-02-05',
    ]);

    // Create a reversed commission
    CommissionEntry::factory()->reversed()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 300.00,
        'earned_date' => '2026-02-05',
    ]);

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // No commission should be included
    expect((float) $stub->commission_amount)->toBe(0.00);
    expect($stub->commission_details)->toBeNull();

    // Gross should just be regular pay: 80 * 25 = 2000
    expect((float) $stub->gross_pay)->toBe(2000.00);
});

it('generates stub without commission when employee has none', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    expect((float) $stub->commission_amount)->toBe(0.00);
    expect($stub->commission_details)->toBeNull();
    expect((float) $stub->gross_pay)->toBe(2000.00);
    expect((float) $stub->ytd_commission)->toBe(0.00);
});

it('does not include commissions earned after period end date', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Commission earned within the period
    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 300.00,
        'earned_date' => '2026-02-10',
    ]);

    // Commission earned after the period
    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 500.00,
        'earned_date' => '2026-02-20',
    ]);

    $stub = $this->service->generatePayStub($employee, $period, 80, 0, 0);

    // Only the commission within the period should be included
    expect((float) $stub->commission_amount)->toBe(300.00);
    expect($stub->commission_details)->toHaveCount(1);
});

it('does not include commissions already paid on another stub', function () {
    $employee = EmployeeProfile::factory()
        ->withW4()
        ->create([
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

    $period1 = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-14',
    ]);

    // Create approved commission and generate first stub (marks as paid)
    CommissionEntry::factory()->approved()->create([
        'employee_profile_id' => $employee->id,
        'amount' => 500.00,
        'earned_date' => '2026-02-05',
    ]);

    $stub1 = $this->service->generatePayStub($employee, $period1, 80, 0, 0);
    expect((float) $stub1->commission_amount)->toBe(500.00);

    // Create a second period - the already-paid commission should not appear
    $period2 = PayPeriod::factory()->biweekly()->create([
        'start_date' => '2026-02-15',
        'end_date' => '2026-02-28',
    ]);

    $stub2 = $this->service->generatePayStub($employee, $period2, 80, 0, 0);

    expect((float) $stub2->commission_amount)->toBe(0.00);
    expect($stub2->commission_details)->toBeNull();
});
