<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\PtoRequest;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\PayStubAvailable;

class PayrollService
{
    public function __construct(
        private TaxCalculationService $taxService,
        private CommissionService $commissionService,
        private ?JournalEntryService $journalEntryService = null,
    ) {
        $this->journalEntryService ??= new JournalEntryService;
    }

    /**
     * Create a new pay period.
     */
    public function createPayPeriod(string $startDate, string $endDate, string $type): PayPeriod
    {
        return PayPeriod::create([
            'start_date' => $startDate,
            'end_date' => $endDate,
            'type' => $type,
            'status' => 'open',
        ]);
    }

    /**
     * Generate a pay stub for an employee in a pay period.
     */
    public function generatePayStub(
        EmployeeProfile $employee,
        PayPeriod $period,
        float $regularHours,
        float $overtimeHours = 0,
        float $ptoHours = 0
    ): PayStub {
        $settings = CompanySetting::getInstance();
        $payRate = (float) $employee->pay_rate;

        if ($employee->isCommissionOnly()) {
            // Commission-only: no base pay from hours/salary
            $regularRate = 0;
            $overtimeRate = 0;
            $grossPay = 0;
        } elseif ($employee->hasSalaryBase()) {
            // Salary or salary+commission: calculate hourly equivalent
            $payPeriodsPerYear = $this->taxService->getPayPeriodsPerYear($period->type);
            $perPeriodPay = $payRate / $payPeriodsPerYear;
            $regularRate = round($payRate / 2080, 2); // 40hrs * 52wks
            $overtimeRate = round($regularRate * ((float) ($settings->overtime_multiplier ?? 1.5)), 2);
            $grossPay = round($perPeriodPay + ($overtimeHours * $overtimeRate), 2);
        } else {
            // Hourly or hourly+commission
            $regularRate = $payRate;
            $overtimeRate = round($payRate * ((float) ($settings->overtime_multiplier ?? 1.5)), 2);
            $grossPay = round(
                ($regularHours * $regularRate) +
                ($overtimeHours * $overtimeRate) +
                ($ptoHours * $regularRate),
                2
            );
        }

        // Get approved commissions for this employee/period
        $commissionEntries = $this->commissionService->getPendingForPayroll($employee, $period);
        $commissionAmount = round((float) $commissionEntries->sum('amount'), 2);
        $commissionDetails = $commissionEntries->isNotEmpty()
            ? $commissionEntries->map(fn ($e) => [
                'id' => $e->id,
                'source_label' => $e->source_label ?? 'Commission',
                'type' => $e->commission_type,
                'amount' => (float) $e->amount,
            ])->values()->all()
            : null;

        // Add commission to gross pay
        $grossPay = round($grossPay + $commissionAmount, 2);

        // Get active deductions for employee
        $pretaxDeductions = $employee->deductions()
            ->active()
            ->pretax()
            ->get();

        $posttaxDeductions = $employee->deductions()
            ->active()
            ->posttax()
            ->get();

        // Calculate pretax deduction amounts
        $pretaxItems = [];
        $totalPretax = 0;
        foreach ($pretaxDeductions as $deduction) {
            $amount = $deduction->calculateAmount($grossPay);
            $pretaxItems[] = [
                'id' => $deduction->id,
                'description' => $deduction->description,
                'type' => $deduction->type,
                'amount' => $amount,
            ];
            $totalPretax += $amount;
        }

        // Taxable gross = gross - pretax deductions
        $taxableGross = max(0, $grossPay - $totalPretax);

        // Calculate YTD totals from previous stubs in the same calendar year
        $ytd = $this->calculateYtd($employee, $period);

        // Calculate taxes based on commission tax method
        $filingStatus = $employee->w4_filing_status ?? 'single';
        $allowances = (int) ($employee->w4_allowances ?? 0);
        $additionalWithholding = (float) ($employee->w4_additional_withholding ?? 0);
        $payPeriodsPerYear = $this->taxService->getPayPeriodsPerYear($period->type);
        $commissionTaxMethod = $employee->commission_tax_method ?? 'aggregate';

        if ($commissionAmount > 0 && $commissionTaxMethod === 'supplemental') {
            // Supplemental method: tax regular pay normally, commission at flat 22%
            $regularTaxableGross = max(0, $taxableGross - $commissionAmount);

            $taxes = $this->taxService->calculateAllTaxes(
                $regularTaxableGross,
                $ytd['gross'],
                $filingStatus,
                $allowances,
                $additionalWithholding,
                $payPeriodsPerYear
            );

            // Add flat 22% federal on commission portion
            $taxes['federal'] = round($taxes['federal'] + ($commissionAmount * 0.22), 2);

            // State tax and FICA still apply to full gross (including commission)
            $taxes['state'] = $this->taxService->calculateColoradoStateTax($taxableGross);
            $taxes['social_security'] = $this->taxService->calculateSocialSecurity($taxableGross, $ytd['gross']);
            $medicareResult = $this->taxService->calculateMedicare($taxableGross, $ytd['gross']);
            $taxes['medicare'] = $medicareResult['medicare'];
            $taxes['additional_medicare'] = $medicareResult['additional_medicare'];
        } else {
            // Aggregate method: commissions added to regular gross, taxed together
            $taxes = $this->taxService->calculateAllTaxes(
                $taxableGross,
                $ytd['gross'],
                $filingStatus,
                $allowances,
                $additionalWithholding,
                $payPeriodsPerYear
            );
        }

        // Calculate posttax deduction amounts
        $posttaxItems = [];
        $totalPosttax = 0;
        foreach ($posttaxDeductions as $deduction) {
            $amount = $deduction->calculateAmount($grossPay);
            $posttaxItems[] = [
                'id' => $deduction->id,
                'description' => $deduction->description,
                'type' => $deduction->type,
                'amount' => $amount,
            ];
            $totalPosttax += $amount;
        }

        // Total deductions = taxes + pretax + posttax
        $totalTaxes = $taxes['federal'] + $taxes['state'] + $taxes['social_security']
            + $taxes['medicare'] + $taxes['additional_medicare'];
        $totalDeductions = round($totalPretax + $totalTaxes + $totalPosttax, 2);

        // Net pay
        $netPay = round($grossPay - $totalDeductions, 2);

        $stub = PayStub::create([
            'employee_profile_id' => $employee->id,
            'pay_period_id' => $period->id,
            'hours_regular' => $regularHours,
            'hours_overtime' => $overtimeHours,
            'hours_pto' => $ptoHours,
            'rate_regular' => $regularRate,
            'rate_overtime' => $overtimeRate,
            'gross_pay' => $grossPay,
            'commission_amount' => $commissionAmount,
            'commission_details' => $commissionDetails,
            'federal_tax' => $taxes['federal'],
            'state_tax' => $taxes['state'],
            'local_tax' => 0,
            'social_security' => $taxes['social_security'],
            'medicare' => $taxes['medicare'],
            'additional_medicare' => $taxes['additional_medicare'],
            'pretax_deductions' => ! empty($pretaxItems) ? $pretaxItems : null,
            'posttax_deductions' => ! empty($posttaxItems) ? $posttaxItems : null,
            'total_deductions' => $totalDeductions,
            'net_pay' => $netPay,
            'ytd_gross' => round($ytd['gross'] + $grossPay, 2),
            'ytd_federal_tax' => round($ytd['federal_tax'] + $taxes['federal'], 2),
            'ytd_state_tax' => round($ytd['state_tax'] + $taxes['state'], 2),
            'ytd_social_security' => round($ytd['social_security'] + $taxes['social_security'], 2),
            'ytd_medicare' => round($ytd['medicare'] + $taxes['medicare'] + $taxes['additional_medicare'], 2),
            'ytd_commission' => round($ytd['commission'] + $commissionAmount, 2),
            'status' => 'draft',
        ]);

        // Mark commission entries as paid
        if ($commissionEntries->isNotEmpty()) {
            $this->commissionService->markAsPaid($commissionEntries, $period->id, $stub->id);
        }

        return $stub;
    }

    /**
     * Run payroll for all active employees in a pay period.
     *
     * @param  array<int, array{regular: float, overtime: float, pto: float}>  $hoursData  Keyed by employee_profile_id
     * @return array<int, PayStub>
     */
    public function runPayroll(PayPeriod $period, array $hoursData = []): array
    {
        $employees = EmployeeProfile::query()
            ->where('employment_status', 'active')
            ->where(fn ($q) => $q->whereNotNull('pay_rate')->orWhere('pay_type', 'commission'))
            ->get();

        $stubs = [];

        foreach ($employees as $employee) {
            $hours = $hoursData[$employee->id] ?? [
                'regular' => 40,
                'overtime' => 0,
                'pto' => 0,
            ];

            $stubs[] = $this->generatePayStub(
                $employee,
                $period,
                $hours['regular'],
                $hours['overtime'],
                $hours['pto']
            );
        }

        // Mark period as processing
        $period->update(['status' => 'processing']);

        return $stubs;
    }

    /**
     * Finalize payroll for a pay period.
     */
    public function finalizePayroll(PayPeriod $period, User $processedBy): void
    {
        // Finalize all draft stubs and assign check numbers
        $stubs = $period->payStubs()->where('status', 'draft')->get();
        $checkNumber = $this->getNextCheckNumber();

        foreach ($stubs as $stub) {
            $stub->update([
                'status' => 'finalized',
                'check_number' => $checkNumber,
            ]);
            $checkNumber = (string) ((int) $checkNumber + 1);
        }

        // Notify employees and create journal entries for finalized stubs
        $finalizedStubs = $period->payStubs()
            ->where('status', 'finalized')
            ->with('employeeProfile.user')
            ->get();

        foreach ($finalizedStubs as $stub) {
            if ($stub->employeeProfile?->user) {
                $stub->employeeProfile->user->notify(new PayStubAvailable($stub));
            }

            // Create GL journal entry for each pay stub
            try {
                $this->journalEntryService->createFromPayStub($stub);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Mark period as completed
        $period->update([
            'status' => 'completed',
            'processed_by' => $processedBy->id,
            'processed_at' => now(),
        ]);
    }

    /**
     * Get the next available check number.
     */
    public function getNextCheckNumber(): string
    {
        $maxCheck = PayStub::query()
            ->whereNotNull('check_number')
            ->orderByRaw('CAST(check_number AS UNSIGNED) DESC')
            ->value('check_number');

        if (! $maxCheck) {
            return '1001';
        }

        return (string) ((int) $maxCheck + 1);
    }

    /**
     * Calculate hours from approved time entries and PTO for a pay period.
     *
     * @return array<int, array{regular: float, overtime: float, pto: float}>
     */
    public function calculateHoursFromTimeEntries(PayPeriod $period): array
    {
        $entries = TimeEntry::query()
            ->where('status', 'approved')
            ->whereNotNull('clock_out')
            ->whereBetween('clock_in', [
                $period->start_date->startOfDay(),
                $period->end_date->endOfDay(),
            ])
            ->get()
            ->groupBy('employee_profile_id');

        $result = [];

        foreach ($entries as $employeeId => $employeeEntries) {
            $totalRegular = 0;
            $totalOvertime = 0;

            foreach ($employeeEntries as $entry) {
                $totalRegular += (float) ($entry->total_hours ?? 0);
                $totalOvertime += (float) ($entry->overtime_hours ?? 0);
            }

            // Regular hours should exclude overtime
            $totalRegular = max(0, $totalRegular - $totalOvertime);

            $result[$employeeId] = [
                'regular' => round($totalRegular, 2),
                'overtime' => round($totalOvertime, 2),
                'pto' => 0.0,
            ];
        }

        // Also get approved PTO for the period
        $ptoRequests = PtoRequest::query()
            ->where('status', 'approved')
            ->where(function ($q) use ($period) {
                $q->whereBetween('start_date', [$period->start_date, $period->end_date])
                    ->orWhereBetween('end_date', [$period->start_date, $period->end_date]);
            })
            ->get();

        foreach ($ptoRequests as $pto) {
            $employeeId = $pto->employee_profile_id;
            if (! isset($result[$employeeId])) {
                $result[$employeeId] = ['regular' => 0.0, 'overtime' => 0.0, 'pto' => 0.0];
            }
            $result[$employeeId]['pto'] += (float) ($pto->hours ?? 8);
        }

        return $result;
    }

    /**
     * Calculate YTD totals for an employee from previous stubs in the same year.
     *
     * @return array{gross: float, federal_tax: float, state_tax: float, social_security: float, medicare: float, commission: float}
     */
    private function calculateYtd(EmployeeProfile $employee, PayPeriod $period): array
    {
        $yearStart = $period->start_date->copy()->startOfYear();

        $ytd = PayStub::query()
            ->where('employee_profile_id', $employee->id)
            ->whereHas('payPeriod', function ($q) use ($yearStart, $period) {
                $q->where('start_date', '>=', $yearStart)
                    ->where('id', '!=', $period->id);
            })
            ->selectRaw('
                COALESCE(SUM(gross_pay), 0) as total_gross,
                COALESCE(SUM(federal_tax), 0) as total_federal,
                COALESCE(SUM(state_tax), 0) as total_state,
                COALESCE(SUM(social_security), 0) as total_ss,
                COALESCE(SUM(medicare + additional_medicare), 0) as total_medicare,
                COALESCE(SUM(commission_amount), 0) as total_commission
            ')
            ->first();

        return [
            'gross' => (float) $ytd->total_gross,
            'federal_tax' => (float) $ytd->total_federal,
            'state_tax' => (float) $ytd->total_state,
            'social_security' => (float) $ytd->total_ss,
            'medicare' => (float) $ytd->total_medicare,
            'commission' => (float) $ytd->total_commission,
        ];
    }
}
