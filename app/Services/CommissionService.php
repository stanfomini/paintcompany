<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\PayPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CommissionService
{
    /**
     * Create commission entries when a lead/deal is assigned.
     * Uses auto_trigger filtering and duplicate prevention.
     *
     * @return array<int, CommissionEntry>
     */
    public function createFromDeal(Deal $deal): array
    {
        $entries = [];

        if ($deal->assigned_to) {
            $employee = $this->findEmployeeForUser($deal->assigned_to);
            if ($employee && ! $this->hasDuplicateCommission($employee, $deal, 'lead')) {
                $entries = array_merge($entries, $this->applyRulesForSource($employee, 'lead', $deal, 0, 0, autoOnly: true));
            }
        }

        return $entries;
    }

    /**
     * Create commission entries when an estimate is accepted.
     * Uses auto_trigger filtering and duplicate prevention.
     *
     * @return array<int, CommissionEntry>
     */
    public function createFromEstimate(Estimate $estimate): array
    {
        $entries = [];
        $deal = $estimate->deal;
        $revenue = (float) $estimate->total;
        $profit = $estimate->getGrossProfit();

        // Commission for assigned user on the deal
        if ($deal?->assigned_to) {
            $employee = $this->findEmployeeForUser($deal->assigned_to);
            if ($employee && ! $this->hasDuplicateCommission($employee, $estimate, 'estimate')) {
                $entries = array_merge(
                    $entries,
                    $this->applyRulesForSource($employee, 'estimate', $estimate, $revenue, $profit, autoOnly: true)
                );
            }
        }

        // Commission for estimate creator (if different from deal assignee)
        if ($estimate->created_by && $estimate->created_by !== ($deal?->assigned_to ?? 0)) {
            $creatorEmployee = $this->findEmployeeForUser($estimate->created_by);
            if ($creatorEmployee && ! $this->hasDuplicateCommission($creatorEmployee, $estimate, 'estimate')) {
                $entries = array_merge(
                    $entries,
                    $this->applyRulesForSource($creatorEmployee, 'estimate', $estimate, $revenue, $profit, autoOnly: true)
                );
            }
        }

        // Commission for referring agent (if different and has employee profile)
        if ($deal?->agent_id
            && $deal->agent_id !== ($deal->assigned_to ?? 0)
            && $deal->agent_id !== ($estimate->created_by ?? 0)
        ) {
            $agentEmployee = $this->findEmployeeForUser($deal->agent_id);
            if ($agentEmployee && ! $this->hasDuplicateCommission($agentEmployee, $estimate, 'estimate')) {
                $entries = array_merge(
                    $entries,
                    $this->applyRulesForSource($agentEmployee, 'estimate', $estimate, $revenue, $profit, autoOnly: true)
                );
            }
        }

        return $entries;
    }

    /**
     * Create commission entries when a job is completed.
     * Uses auto_trigger filtering and duplicate prevention.
     *
     * @return array<int, CommissionEntry>
     */
    public function createFromJob(Job $job): array
    {
        $entries = [];
        $estimate = $job->estimate;
        $deal = $job->deal ?? $estimate?->deal;

        $revenue = $estimate ? (float) $estimate->total : 0;
        $profit = $estimate ? $estimate->getGrossProfit() : 0;

        if ($deal?->assigned_to) {
            $employee = $this->findEmployeeForUser($deal->assigned_to);
            if ($employee && ! $this->hasDuplicateCommission($employee, $job, 'job')) {
                $entries = array_merge(
                    $entries,
                    $this->applyRulesForSource($employee, 'job', $job, $revenue, $profit, autoOnly: true)
                );
            }
        }

        return $entries;
    }

    /**
     * Apply all matching commission rules for a source and create entries.
     *
     * @param  bool  $autoOnly  When true, only rules with auto_trigger enabled are used (for lifecycle hooks).
     * @return array<int, CommissionEntry>
     */
    public function applyRulesForSource(
        EmployeeProfile $employee,
        string $source,
        Model $sourceModel,
        float $revenue = 0,
        float $profit = 0,
        bool $autoOnly = false
    ): array {
        $rules = $autoOnly
            ? $employee->getAutoCommissionRulesForSource($source)
            : $employee->getCommissionRulesForSource($source);
        $entries = [];

        foreach ($rules as $rule) {
            $amount = $this->calculateAmountFromRule($rule, $employee, $revenue, $profit);

            if ($amount <= 0) {
                continue;
            }

            $entries[] = CommissionEntry::create([
                'employee_profile_id' => $employee->id,
                'source_type' => get_class($sourceModel),
                'source_id' => $sourceModel->getKey(),
                'source_label' => $this->buildSourceLabel($source, $sourceModel),
                'commission_type' => $rule['type'],
                'basis_amount' => $this->getBasisAmount($rule, $revenue, $profit),
                'rate' => $rule['rate'] ?? 0,
                'amount' => $amount,
                'status' => 'pending',
                'earned_date' => now()->toDateString(),
            ]);
        }

        return $entries;
    }

    /**
     * Check if a commission entry already exists for this employee + source combo.
     * Prevents duplicate commissions when lifecycle events fire multiple times.
     */
    public function hasDuplicateCommission(EmployeeProfile $employee, Model $sourceModel, string $source): bool
    {
        return CommissionEntry::query()
            ->where('employee_profile_id', $employee->id)
            ->where('source_type', get_class($sourceModel))
            ->where('source_id', $sourceModel->getKey())
            ->whereNotIn('status', ['reversed'])
            ->exists();
    }

    /**
     * Calculate commission amount from a single rule.
     */
    public function calculateAmountFromRule(
        array $rule,
        EmployeeProfile $employee,
        float $revenue = 0,
        float $profit = 0
    ): float {
        $type = $rule['type'] ?? 'flat';
        $rate = (float) ($rule['rate'] ?? 0);
        $amount = (float) ($rule['amount'] ?? 0);

        return match ($type) {
            'revenue_percentage' => round($revenue * $rate, 2),
            'profit_percentage' => round($profit * $rate, 2),
            'flat' => round($amount, 2),
            'tiered' => $this->calculateTieredCommission($employee, $revenue, $rule),
            default => 0,
        };
    }

    /**
     * Calculate tiered commission based on cumulative period performance.
     */
    public function calculateTieredCommission(
        EmployeeProfile $employee,
        float $currentAmount,
        array $rule = []
    ): float {
        $periodType = $rule['period'] ?? 'monthly';
        $tiers = $employee->commissionTiers()
            ->where('period_type', $periodType)
            ->ordered()
            ->get();

        if ($tiers->isEmpty()) {
            return 0;
        }

        $periodStart = $this->getPeriodStart($periodType);
        $cumulativeSales = (float) CommissionEntry::query()
            ->where('employee_profile_id', $employee->id)
            ->where('earned_date', '>=', $periodStart)
            ->whereNotIn('status', ['reversed'])
            ->sum('basis_amount');

        return $this->walkTiers($tiers, $cumulativeSales, $cumulativeSales + $currentAmount);
    }

    /**
     * Walk through tiers calculating commission for the amount range.
     */
    protected function walkTiers(Collection $tiers, float $previousTotal, float $newTotal): float
    {
        $commission = 0;

        foreach ($tiers as $tier) {
            $tierMin = (float) $tier->threshold_min;
            $tierMax = $tier->threshold_max ? (float) $tier->threshold_max : PHP_FLOAT_MAX;

            if ($tierMax <= $previousTotal) {
                continue;
            }

            if ($tierMin >= $newTotal) {
                break;
            }

            $rangeStart = max($tierMin, $previousTotal);
            $rangeEnd = min($tierMax, $newTotal);
            $tierAmount = $rangeEnd - $rangeStart;

            if ($tierAmount > 0) {
                $commission += round($tierAmount * (float) $tier->rate, 2);
            }
        }

        return round($commission, 2);
    }

    /**
     * Approve a commission entry.
     */
    public function approveCommission(CommissionEntry $entry, User $approver): void
    {
        $entry->approve($approver);
    }

    /**
     * Reverse a commission entry.
     */
    public function reverseCommission(CommissionEntry $entry, ?string $reason = null): void
    {
        $entry->reverse($reason);
    }

    /**
     * Get approved commissions ready for a pay period.
     *
     * @return Collection<int, CommissionEntry>
     */
    public function getPendingForPayroll(EmployeeProfile $employee, PayPeriod $period): Collection
    {
        return CommissionEntry::query()
            ->where('employee_profile_id', $employee->id)
            ->where('status', 'approved')
            ->whereNull('pay_stub_id')
            ->where('earned_date', '<=', $period->end_date)
            ->get();
    }

    /**
     * Mark commissions as paid on a pay stub.
     *
     * @param  Collection<int, CommissionEntry>|array<int>  $entries
     */
    public function markAsPaid(Collection|array $entries, int $payPeriodId, int $payStubId): void
    {
        $ids = $entries instanceof Collection ? $entries->pluck('id') : $entries;

        CommissionEntry::whereIn('id', $ids)->update([
            'status' => 'paid',
            'pay_period_id' => $payPeriodId,
            'pay_stub_id' => $payStubId,
        ]);
    }

    /**
     * Create a manual commission entry.
     */
    public function createManual(
        EmployeeProfile $employee,
        float $amount,
        string $type = 'flat',
        ?string $notes = null,
        ?float $basisAmount = null,
        ?float $rate = null
    ): CommissionEntry {
        return CommissionEntry::create([
            'employee_profile_id' => $employee->id,
            'commission_type' => $type,
            'basis_amount' => $basisAmount ?? 0,
            'rate' => $rate ?? 0,
            'amount' => round($amount, 2),
            'status' => 'pending',
            'earned_date' => now()->toDateString(),
            'source_label' => 'Manual Entry',
            'notes' => $notes,
        ]);
    }

    /**
     * Get the period start date for tiered calculations.
     */
    protected function getPeriodStart(string $periodType): string
    {
        return match ($periodType) {
            'monthly' => now()->startOfMonth()->toDateString(),
            'quarterly' => now()->startOfQuarter()->toDateString(),
            'yearly' => now()->startOfYear()->toDateString(),
            default => now()->startOfMonth()->toDateString(),
        };
    }

    /**
     * Find an EmployeeProfile for a given user ID.
     */
    protected function findEmployeeForUser(int $userId): ?EmployeeProfile
    {
        return EmployeeProfile::query()
            ->where('user_id', $userId)
            ->where('employment_status', 'active')
            ->first();
    }

    /**
     * Build a human-readable source label.
     */
    protected function buildSourceLabel(string $source, Model $sourceModel): string
    {
        return match ($source) {
            'lead' => 'Lead: '.($sourceModel->customer_name ?? $sourceModel->title ?? "#{$sourceModel->id}"),
            'estimate' => 'Estimate: '.($sourceModel->estimate_number ?? "#{$sourceModel->id}"),
            'job' => 'Job: '.($sourceModel->title ?? "#{$sourceModel->id}"),
            default => ucfirst($source)." #{$sourceModel->id}",
        };
    }

    /**
     * Get the basis amount based on commission rule type.
     */
    protected function getBasisAmount(array $rule, float $revenue, float $profit): float
    {
        return match ($rule['type'] ?? 'flat') {
            'revenue_percentage', 'tiered' => $revenue,
            'profit_percentage' => $profit,
            default => 0,
        };
    }
}
