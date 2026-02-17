<?php

use App\Models\EmployeeDeduction;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\User;
use App\Services\PayrollService;
use App\Services\TaxCalculationService;
use Livewire\Livewire;

// ──────────────────────────────────────────────────
// TaxCalculationService Tests
// ──────────────────────────────────────────────────

describe('TaxCalculationService', function () {
    beforeEach(function () {
        $this->taxService = new TaxCalculationService;
    });

    it('calculates federal tax with progressive brackets for single filer', function () {
        // $2000 biweekly gross, single, 0 allowances, 0 additional
        $tax = $this->taxService->calculateFederalTax(2000, 'single', 0, 0, 26);

        // Annual = $52,000, minus $14,600 standard deduction = $37,400 taxable
        // 10% on first $11,925 = $1,192.50
        // 12% on ($37,400 - $11,925) = $3,057.00
        // Total annual = ~$4,249.50, per period = ~$163.44
        expect($tax)->toBeGreaterThan(100);
        expect($tax)->toBeLessThan(250);
    });

    it('calculates zero federal tax when below standard deduction', function () {
        // Very low income: $200 biweekly = $5,200 annual, below $14,600 standard deduction
        $tax = $this->taxService->calculateFederalTax(200, 'single', 0, 0, 26);

        expect($tax)->toBe(0.0);
    });

    it('adds additional withholding to federal tax', function () {
        $taxWithout = $this->taxService->calculateFederalTax(2000, 'single', 0, 0, 26);
        $taxWith = $this->taxService->calculateFederalTax(2000, 'single', 0, 50, 26);

        expect($taxWith)->toBe(round($taxWithout + 50, 2));
    });

    it('reduces federal tax with allowances', function () {
        $taxNoAllowances = $this->taxService->calculateFederalTax(2000, 'single', 0, 0, 26);
        $taxWithAllowances = $this->taxService->calculateFederalTax(2000, 'single', 2, 0, 26);

        expect($taxWithAllowances)->toBeLessThan($taxNoAllowances);
    });

    it('calculates married filing status with higher brackets', function () {
        $singleTax = $this->taxService->calculateFederalTax(3000, 'single', 0, 0, 26);
        $marriedTax = $this->taxService->calculateFederalTax(3000, 'married', 0, 0, 26);

        // Married has higher standard deduction and wider brackets = less tax
        expect($marriedTax)->toBeLessThan($singleTax);
    });

    it('calculates social security tax', function () {
        $ss = $this->taxService->calculateSocialSecurity(2000, 0);

        // 2000 * 0.062 = $124
        expect($ss)->toBe(124.00);
    });

    it('caps social security at wage base', function () {
        // YTD already at $168,000 (wage base is $168,600)
        $ss = $this->taxService->calculateSocialSecurity(2000, 168000);

        // Only $600 remaining under cap: 600 * 0.062 = $37.20
        expect($ss)->toBe(37.20);
    });

    it('returns zero social security when YTD exceeds wage base', function () {
        $ss = $this->taxService->calculateSocialSecurity(2000, 170000);

        expect($ss)->toBe(0.0);
    });

    it('calculates regular medicare tax', function () {
        $result = $this->taxService->calculateMedicare(2000, 0);

        // 2000 * 0.0145 = $29.00
        expect($result['medicare'])->toBe(29.00);
        expect((float) $result['additional_medicare'])->toBe(0.0);
    });

    it('calculates additional medicare over 200k threshold', function () {
        // YTD at $199,000, this paycheck pushes over $200,000
        $result = $this->taxService->calculateMedicare(3000, 199000);

        // Regular: 3000 * 0.0145 = $43.50
        expect($result['medicare'])->toBe(43.50);

        // Additional: crosses at 200k, so 2000 over threshold * 0.009 = $18.00
        expect($result['additional_medicare'])->toBe(18.00);
    });

    it('calculates additional medicare when already over threshold', function () {
        // YTD already over 200k
        $result = $this->taxService->calculateMedicare(5000, 250000);

        // Regular: 5000 * 0.0145 = $72.50
        expect($result['medicare'])->toBe(72.50);

        // Additional: entire 5000 * 0.009 = $45.00
        expect($result['additional_medicare'])->toBe(45.00);
    });

    it('calculates Colorado state tax at 4.4 percent', function () {
        $tax = $this->taxService->calculateColoradoStateTax(2000);

        // 2000 * 0.044 = $88.00
        expect($tax)->toBe(88.00);
    });

    it('calculates all taxes together', function () {
        $taxes = $this->taxService->calculateAllTaxes(2000, 0, 'single', 0, 0, 26);

        expect($taxes)->toHaveKey('federal');
        expect($taxes)->toHaveKey('state');
        expect($taxes)->toHaveKey('social_security');
        expect($taxes)->toHaveKey('medicare');
        expect($taxes)->toHaveKey('additional_medicare');

        expect($taxes['state'])->toBe(88.00); // 2000 * 0.044
        expect($taxes['social_security'])->toBe(124.00); // 2000 * 0.062
        expect($taxes['medicare'])->toBe(29.00); // 2000 * 0.0145
        expect((float) $taxes['additional_medicare'])->toBe(0.0);
    });

    it('returns correct pay periods per year', function () {
        expect($this->taxService->getPayPeriodsPerYear('weekly'))->toBe(52);
        expect($this->taxService->getPayPeriodsPerYear('biweekly'))->toBe(26);
        expect($this->taxService->getPayPeriodsPerYear('semimonthly'))->toBe(24);
        expect($this->taxService->getPayPeriodsPerYear('monthly'))->toBe(12);
        expect($this->taxService->getPayPeriodsPerYear('unknown'))->toBe(26);
    });
});

// ──────────────────────────────────────────────────
// PayPeriod Model Tests
// ──────────────────────────────────────────────────

describe('PayPeriod model', function () {
    it('creates an open pay period by default', function () {
        $period = PayPeriod::factory()->create();

        expect($period->isOpen())->toBeTrue();
        expect($period->isCompleted())->toBeFalse();
    });

    it('identifies completed periods', function () {
        $period = PayPeriod::factory()->completed()->create();

        expect($period->isCompleted())->toBeTrue();
        expect($period->isOpen())->toBeFalse();
    });

    it('calculates duration in days', function () {
        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
        ]);

        expect($period->getDurationDays())->toBe(14);
    });

    it('has many pay stubs', function () {
        $period = PayPeriod::factory()->create();
        PayStub::factory()->count(3)->create(['pay_period_id' => $period->id]);

        expect($period->payStubs)->toHaveCount(3);
    });
});

// ──────────────────────────────────────────────────
// PayStub Model Tests
// ──────────────────────────────────────────────────

describe('PayStub model', function () {
    it('creates a draft pay stub by default', function () {
        $stub = PayStub::factory()->create();

        expect($stub->status)->toBe('draft');
        expect($stub->isFinalized())->toBeFalse();
    });

    it('identifies finalized pay stubs', function () {
        $stub = PayStub::factory()->finalized()->create();

        expect($stub->isFinalized())->toBeTrue();
    });

    it('belongs to employee and pay period', function () {
        $employee = EmployeeProfile::factory()->create();
        $period = PayPeriod::factory()->create();

        $stub = PayStub::factory()->create([
            'employee_profile_id' => $employee->id,
            'pay_period_id' => $period->id,
        ]);

        expect($stub->employeeProfile->id)->toBe($employee->id);
        expect($stub->payPeriod->id)->toBe($period->id);
    });
});

// ──────────────────────────────────────────────────
// EmployeeDeduction Model Tests
// ──────────────────────────────────────────────────

describe('EmployeeDeduction model', function () {
    it('calculates flat amount deduction', function () {
        $deduction = EmployeeDeduction::factory()->create([
            'amount' => 150.00,
            'is_percentage' => false,
        ]);

        expect($deduction->calculateAmount(5000))->toBe(150.00);
    });

    it('calculates percentage-based deduction', function () {
        $deduction = EmployeeDeduction::factory()->percentage()->create([
            'amount' => 6.00, // 6%
        ]);

        // 6% of $5000 = $300
        expect($deduction->calculateAmount(5000))->toBe(300.00);
    });

    it('scopes active deductions', function () {
        EmployeeDeduction::factory()->create(['is_active' => true]);
        EmployeeDeduction::factory()->inactive()->create();

        expect(EmployeeDeduction::active()->count())->toBe(1);
    });

    it('scopes pretax deductions', function () {
        EmployeeDeduction::factory()->create(['is_pretax' => true]);
        EmployeeDeduction::factory()->posttax()->create();

        expect(EmployeeDeduction::pretax()->count())->toBe(1);
    });

    it('scopes posttax deductions', function () {
        EmployeeDeduction::factory()->create(['is_pretax' => true]);
        EmployeeDeduction::factory()->posttax()->create();

        expect(EmployeeDeduction::posttax()->count())->toBe(1);
    });
});

// ──────────────────────────────────────────────────
// PayrollService Tests
// ──────────────────────────────────────────────────

describe('PayrollService', function () {
    it('creates a new pay period', function () {
        $service = app(PayrollService::class);

        $period = $service->createPayPeriod('2026-02-01', '2026-02-14', 'biweekly');

        expect($period)->toBeInstanceOf(PayPeriod::class);
        expect($period->status)->toBe('open');
        expect($period->type)->toBe('biweekly');
        expect($period->start_date->toDateString())->toBe('2026-02-01');
        expect($period->end_date->toDateString())->toBe('2026-02-14');
    });

    it('generates pay stub for hourly employee', function () {
        $service = app(PayrollService::class);

        $employee = EmployeeProfile::factory()->withW4()->create([
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
            'type' => 'biweekly',
        ]);

        $stub = $service->generatePayStub($employee, $period, 80, 5, 0);

        expect($stub)->toBeInstanceOf(PayStub::class);
        expect($stub->status)->toBe('draft');
        expect((float) $stub->hours_regular)->toBe(80.0);
        expect((float) $stub->hours_overtime)->toBe(5.0);
        expect((float) $stub->rate_regular)->toBe(25.0);
        expect((float) $stub->rate_overtime)->toBe(37.50); // 25 * 1.5

        // Gross = (80 * 25) + (5 * 37.5) = 2000 + 187.5 = 2187.50
        expect((float) $stub->gross_pay)->toBe(2187.50);

        // All tax fields should be set
        expect((float) $stub->federal_tax)->toBeGreaterThan(0);
        expect((float) $stub->state_tax)->toBeGreaterThan(0);
        expect((float) $stub->social_security)->toBeGreaterThan(0);
        expect((float) $stub->medicare)->toBeGreaterThan(0);

        // Net pay should be less than gross
        expect((float) $stub->net_pay)->toBeLessThan((float) $stub->gross_pay);
        expect((float) $stub->net_pay)->toBeGreaterThan(0);
    });

    it('generates pay stub for salaried employee', function () {
        $service = app(PayrollService::class);

        $employee = EmployeeProfile::factory()->salaried()->withW4()->create([
            'pay_rate' => 65000.00,
            'w4_filing_status' => 'married',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
            'type' => 'biweekly',
        ]);

        $stub = $service->generatePayStub($employee, $period, 80, 0, 0);

        // Salary: 65000 / 26 = 2500 per period
        expect((float) $stub->gross_pay)->toBe(2500.00);
        expect($stub->status)->toBe('draft');
    });

    it('handles pretax deductions that reduce taxable income', function () {
        $service = app(PayrollService::class);

        $employee = EmployeeProfile::factory()->withW4()->create([
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        // Add pretax deduction
        EmployeeDeduction::factory()->create([
            'employee_profile_id' => $employee->id,
            'amount' => 200.00,
            'is_percentage' => false,
            'is_pretax' => true,
            'is_active' => true,
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
            'type' => 'biweekly',
        ]);

        $stubWithDeduction = $service->generatePayStub($employee, $period, 80, 0, 0);

        // Gross = 80 * 25 = 2000
        expect((float) $stubWithDeduction->gross_pay)->toBe(2000.00);

        // Pretax deductions should be recorded
        expect($stubWithDeduction->pretax_deductions)->not->toBeNull();
        expect($stubWithDeduction->pretax_deductions)->toHaveCount(1);
        expect((float) $stubWithDeduction->pretax_deductions[0]['amount'])->toBe(200.0);
    });

    it('handles posttax deductions', function () {
        $service = app(PayrollService::class);

        $employee = EmployeeProfile::factory()->withW4()->create([
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        // Add posttax deduction
        EmployeeDeduction::factory()->posttax()->create([
            'employee_profile_id' => $employee->id,
            'amount' => 100.00,
            'is_percentage' => false,
            'is_active' => true,
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
            'type' => 'biweekly',
        ]);

        $stub = $service->generatePayStub($employee, $period, 80, 0, 0);

        expect($stub->posttax_deductions)->not->toBeNull();
        expect($stub->posttax_deductions)->toHaveCount(1);
    });

    it('accumulates YTD totals across pay stubs', function () {
        $service = app(PayrollService::class);

        $employee = EmployeeProfile::factory()->withW4()->create([
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        $period1 = PayPeriod::factory()->create([
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-14',
            'type' => 'biweekly',
        ]);

        $stub1 = $service->generatePayStub($employee, $period1, 80, 0, 0);

        $period2 = PayPeriod::factory()->create([
            'start_date' => '2026-01-15',
            'end_date' => '2026-01-28',
            'type' => 'biweekly',
        ]);

        $stub2 = $service->generatePayStub($employee, $period2, 80, 0, 0);

        // YTD gross should include both periods
        expect((float) $stub2->ytd_gross)->toBe((float) $stub1->gross_pay + (float) $stub2->gross_pay);
    });

    it('finalizes payroll with check numbers', function () {
        $service = app(PayrollService::class);
        $admin = User::factory()->internal()->withFullAccess()->create();

        $period = PayPeriod::factory()->create(['status' => 'processing']);
        PayStub::factory()->create([
            'pay_period_id' => $period->id,
            'status' => 'draft',
        ]);
        PayStub::factory()->create([
            'pay_period_id' => $period->id,
            'status' => 'draft',
        ]);

        $service->finalizePayroll($period, $admin);

        $period->refresh();

        expect($period->status)->toBe('completed');
        expect($period->processed_by)->toBe($admin->id);
        expect($period->processed_at)->not->toBeNull();

        $stubs = $period->payStubs;
        foreach ($stubs as $stub) {
            expect($stub->status)->toBe('finalized');
            expect($stub->check_number)->not->toBeNull();
        }
    });

    it('assigns sequential check numbers starting from 1001', function () {
        $service = app(PayrollService::class);

        expect($service->getNextCheckNumber())->toBe('1001');

        // Create a stub with a check number
        PayStub::factory()->finalized()->create(['check_number' => '1001']);

        expect($service->getNextCheckNumber())->toBe('1002');
    });

    it('runs payroll for all active employees', function () {
        $service = app(PayrollService::class);

        $emp1 = EmployeeProfile::factory()->withW4()->create([
            'employment_status' => 'active',
            'pay_type' => 'hourly',
            'pay_rate' => 25.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        $emp2 = EmployeeProfile::factory()->withW4()->create([
            'employment_status' => 'active',
            'pay_type' => 'hourly',
            'pay_rate' => 30.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        // Terminated employee should not get a pay stub
        EmployeeProfile::factory()->terminated()->create();

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
            'type' => 'biweekly',
        ]);

        $stubs = $service->runPayroll($period, [
            $emp1->id => ['regular' => 80, 'overtime' => 0, 'pto' => 0],
            $emp2->id => ['regular' => 80, 'overtime' => 5, 'pto' => 0],
        ]);

        expect($stubs)->toHaveCount(2);
        expect($period->fresh()->status)->toBe('processing');
    });

    it('uses default 40 hours when no hours data provided', function () {
        $service = app(PayrollService::class);

        $employee = EmployeeProfile::factory()->withW4()->create([
            'employment_status' => 'active',
            'pay_type' => 'hourly',
            'pay_rate' => 20.00,
            'w4_filing_status' => 'single',
            'w4_allowances' => 0,
            'w4_additional_withholding' => 0,
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
            'type' => 'biweekly',
        ]);

        // No hours data provided at all
        $stubs = $service->runPayroll($period);

        expect($stubs)->toHaveCount(1);
        expect((float) $stubs[0]->hours_regular)->toBe(40.0);
    });
});

// ──────────────────────────────────────────────────
// Check PDF Route Tests
// ──────────────────────────────────────────────────

describe('Check PDF route', function () {
    it('downloads check PDF for authorized users', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();
        $stub = PayStub::factory()->finalized()->create();

        $response = $this->actingAs($admin)->get(route('payroll.check', $stub));

        $response->assertSuccessful();
        expect($response->headers->get('content-type'))->toContain('pdf');
    });

    it('denies check PDF for unauthorized users', function () {
        $user = User::factory()->internal()->create();
        $stub = PayStub::factory()->finalized()->create();

        $response = $this->actingAs($user)->get(route('payroll.check', $stub));

        $response->assertForbidden();
    });

    it('denies check PDF for guest users', function () {
        $stub = PayStub::factory()->finalized()->create();

        $response = $this->get(route('payroll.check', $stub));

        $response->assertRedirect();
    });
});

// ──────────────────────────────────────────────────
// Import Hours from Time Entries (Livewire) Tests
// ──────────────────────────────────────────────────

describe('Payroll run import hours', function () {
    it('imports hours from time entries into payroll run form', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $employee = EmployeeProfile::factory()->withW4()->create([
            'employment_status' => 'active',
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
        ]);

        // Create approved time entries for this employee in the period
        \App\Models\TimeEntry::factory()->approved()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => '2026-02-03 08:00:00',
            'clock_out' => '2026-02-03 16:00:00',
            'total_hours' => 8.00,
            'overtime_hours' => 0,
        ]);

        \App\Models\TimeEntry::factory()->approved()->create([
            'employee_profile_id' => $employee->id,
            'clock_in' => '2026-02-04 07:00:00',
            'clock_out' => '2026-02-04 17:00:00',
            'total_hours' => 10.00,
            'overtime_hours' => 2.00,
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.run', ['period' => $period])
            ->assertSet("hoursData.{$employee->id}.regular", '40.00')
            ->call('importHoursFromTimeEntries')
            ->assertSet("hoursData.{$employee->id}.regular", '16.00')
            ->assertSet("hoursData.{$employee->id}.overtime", '2.00');
    });

    it('includes PTO hours in import', function () {
        $admin = User::factory()->internal()->withFullAccess()->create();

        $employee = EmployeeProfile::factory()->withW4()->create([
            'employment_status' => 'active',
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
        ]);

        \App\Models\PtoRequest::factory()->approved()->create([
            'employee_profile_id' => $employee->id,
            'start_date' => '2026-02-10',
            'end_date' => '2026-02-10',
            'hours' => 8.0,
        ]);

        Livewire::actingAs($admin)
            ->test('payroll.run', ['period' => $period])
            ->call('importHoursFromTimeEntries')
            ->assertSet("hoursData.{$employee->id}.pto", '8.00');
    });

    it('denies import for non-managers', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('payroll.view');

        $employee = EmployeeProfile::factory()->withW4()->create([
            'employment_status' => 'active',
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

        $period = PayPeriod::factory()->create([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-14',
        ]);

        Livewire::actingAs($user)
            ->test('payroll.run', ['period' => $period])
            ->call('importHoursFromTimeEntries')
            ->assertForbidden();
    });
});
