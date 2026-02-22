<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CommissionEntry;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\PayPeriod;
use App\Models\SalesTerritory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CommissionService
{
    /**
     * Get the effective commission rules for an employee.
     * Merges company defaults (by territory role) with personal overrides.
     * Overrides replace defaults where source matches; additional overrides are appended.
     *
     * @return array<int, array{source: string, type: string, rate?: float, amount?: float, auto_trigger: bool}>
     */
    public function getEffectiveRulesForEmployee(EmployeeProfile $employee, ?string $roleOverride = null): array
    {
        $role = $roleOverride ?? $this->resolveEmployeeTerritoryRole($employee);

        $defaults = $role
            ? CompanySetting::getInstance()->getCommissionDefaultsForRole($role)
            : [];

        $overrides = collect($employee->commission_rules ?? [])
            ->filter(fn (array $rule) => ($rule['active'] ?? true))
            ->values()
            ->all();

        $overrideSources = collect($overrides)->pluck('source')->unique()->all();

        return collect($defaults)
            ->reject(fn (array $rule) => in_array($rule['source'] ?? '', $overrideSources))
            ->values()
            ->merge($overrides)
            ->values()
            ->all();
    }

    /**
     * Resolve the primary territory role for an employee.
     */
    public function resolveEmployeeTerritoryRole(EmployeeProfile $employee): ?string
    {
        $userId = $employee->user_id;

        $membership = DB::table('territory_team_members')
            ->where('user_id', $userId)
            ->first();

        if ($membership) {
            return $membership->role;
        }

        $isManager = SalesTerritory::where('manager_id', $userId)->exists();
        if ($isManager) {
            return 'manager_override';
        }

        return null;
    }

    /**
     * Get auto-trigger effective rules for a specific source.
     *
     * @return array<int, array>
     */
    public function getAutoEffectiveRulesForSource(EmployeeProfile $employee, string $source, ?string $roleOverride = null): array
    {
        return collect($this->getEffectiveRulesForEmployee($employee, $roleOverride))
            ->filter(fn (array $rule) => ($rule['source'] ?? '') === $source && ($rule['auto_trigger'] ?? true))
            ->values()
            ->all();
    }

    /**
     * Get the full commission attribution chain for a deal.
     *
     * @return array{
     *     lead_source: ?string,
     *     knocker: ?array{name: string, knock_date: string, knock_address: string},
     *     territory_path: ?string,
     *     estimator: ?array{name: string},
     *     entries: array<int, array{person_name: string, role: string, source_label: string, amount: float, status: string}>,
     *     total_amount: float,
     *     revenue: float,
     *     commission_percent: float
     * }
     */
    public function getCommissionChainForDeal(Deal $deal): array
    {
        $chain = [
            'lead_source' => $deal->lead_source,
            'knocker' => null,
            'territory_path' => null,
            'estimator' => null,
            'entries' => [],
            'total_amount' => 0,
            'revenue' => (float) ($deal->actual_value ?? $deal->estimated_value ?? 0),
            'commission_percent' => 0,
        ];

        if ($deal->origin_door_knock_id) {
            $knock = $deal->originDoorKnock()->with('user', 'territory')->first();
            if ($knock) {
                $chain['knocker'] = [
                    'name' => $knock->user?->name ?? 'Unknown',
                    'knock_date' => $knock->knocked_at?->format('M j, Y') ?? '-',
                    'knock_address' => $knock->address ?? '-',
                ];

                if ($knock->territory) {
                    $ancestors = $knock->territory->getAncestors();
                    $path = $ancestors->pluck('name')->reverse()->push($knock->territory->name)->implode(' > ');
                    $chain['territory_path'] = $path;
                }
            }
        }

        if ($deal->assigned_to) {
            $assignee = $deal->assignedTo;
            if ($assignee) {
                $chain['estimator'] = ['name' => $assignee->name];
            }
        }

        $estimateIds = $deal->estimates()->pluck('id')->all();
        $jobId = $deal->job?->id;

        $entries = CommissionEntry::query()
            ->with(['employeeProfile.user'])
            ->where(function ($q) use ($deal, $estimateIds, $jobId) {
                $q->where(function ($q2) use ($deal) {
                    $q2->where('source_type', Deal::class)->where('source_id', $deal->id);
                });

                if (! empty($estimateIds)) {
                    $q->orWhere(function ($q2) use ($estimateIds) {
                        $q2->where('source_type', Estimate::class)->whereIn('source_id', $estimateIds);
                    });
                }

                if ($jobId) {
                    $q->orWhere(function ($q2) use ($jobId) {
                        $q2->where('source_type', Job::class)->where('source_id', $jobId);
                    });
                }

                if ($deal->origin_door_knock_id) {
                    $q->orWhere(function ($q2) use ($deal) {
                        $q2->where('source_type', DoorKnock::class)->where('source_id', $deal->origin_door_knock_id);
                    });
                }
            })
            ->orderBy('earned_date')
            ->get();

        $totalAmount = 0;
        foreach ($entries as $entry) {
            $personName = $entry->employeeProfile?->user?->name ?? 'Unknown';
            $chain['entries'][] = [
                'id' => $entry->id,
                'person_name' => $personName,
                'role' => $this->inferRoleFromSource($entry->source_label ?? ''),
                'source_label' => $entry->source_label ?? '-',
                'commission_type' => $entry->commission_type,
                'amount' => (float) $entry->amount,
                'status' => $entry->status,
                'earned_date' => $entry->earned_date?->format('M j, Y'),
            ];
            $totalAmount += (float) $entry->amount;
        }

        $chain['total_amount'] = round($totalAmount, 2);
        if ($chain['revenue'] > 0) {
            $chain['commission_percent'] = round(($totalAmount / $chain['revenue']) * 100, 1);
        }

        return $chain;
    }

    /**
     * Infer role from source label.
     */
    protected function inferRoleFromSource(string $sourceLabel): string
    {
        if (str_starts_with($sourceLabel, 'Door Knock:')) {
            return 'Knocker';
        }
        if (str_starts_with($sourceLabel, 'Estimate:')) {
            return 'Estimator';
        }
        if (str_starts_with($sourceLabel, 'Territory Override:')) {
            return 'Manager';
        }
        if (str_starts_with($sourceLabel, 'Job:')) {
            return 'Sales';
        }
        if (str_starts_with($sourceLabel, 'Lead:')) {
            return 'Lead Gen';
        }
        if (str_starts_with($sourceLabel, 'Crew Bonus:')) {
            return 'Crew';
        }

        return 'Other';
    }

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
     * Create a crew bonus commission when a job is completed.
     * Uses CrewProfile.bonus_percent to calculate the bonus.
     *
     * @return array<int, CommissionEntry>
     */
    public function createCrewBonusFromJob(Job $job): array
    {
        $entries = [];
        $estimate = $job->estimate;
        $revenue = $estimate ? (float) $estimate->total : 0;

        if ($revenue <= 0) {
            return $entries;
        }

        $crews = $job->crews()->wherePivot('status', 'accepted')->get();

        foreach ($crews as $crewUser) {
            $crewProfile = $crewUser->crewProfile;
            if (! $crewProfile || ! $crewProfile->bonus_percent || $crewProfile->bonus_percent <= 0) {
                continue;
            }

            $bonusAmount = $crewProfile->calculateBonusAmount($revenue);
            if (! $bonusAmount || $bonusAmount <= 0) {
                continue;
            }

            // Duplicate guard: check if crew bonus already exists for this job
            $exists = CommissionEntry::query()
                ->where('source_type', Job::class)
                ->where('source_id', $job->id)
                ->where('source_label', 'like', 'Crew Bonus:%')
                ->whereHas('employeeProfile', fn ($q) => $q->where('user_id', $crewUser->id))
                ->whereNotIn('status', ['reversed'])
                ->exists();

            if ($exists) {
                continue;
            }

            $employeeProfile = EmployeeProfile::where('user_id', $crewUser->id)
                ->where('employment_status', 'active')
                ->first();

            if ($employeeProfile) {
                $entries[] = CommissionEntry::create(array_merge(
                    $this->commissionableFieldsFor($employeeProfile),
                    [
                        'source_type' => Job::class,
                        'source_id' => $job->id,
                        'source_label' => "Crew Bonus: {$job->title}",
                        'commission_type' => 'flat',
                        'basis_amount' => $revenue,
                        'rate' => round($crewProfile->bonus_percent / 100, 4),
                        'amount' => $bonusAmount,
                        'status' => 'pending',
                        'earned_date' => now()->toDateString(),
                    ]
                ));
            } else {
                $cogsAccount = Account::where('company_setting_id', CompanySetting::getInstance()->id)
                    ->where('code', '5010')->first();
                $apAccount = Account::where('company_setting_id', CompanySetting::getInstance()->id)
                    ->where('code', '2000')->first();

                JobPayable::create([
                    'job_id' => $job->id,
                    'payee_type' => JobPayable::PAYEE_TYPE_CREW,
                    'payee_user_id' => $crewUser->id,
                    'payee_name' => $crewUser->name,
                    'category' => JobPayable::CATEGORY_BONUS,
                    'description' => "Crew Bonus: {$crewProfile->bonus_percent}% of \${$revenue}",
                    'amount' => $bonusAmount,
                    'status' => JobPayable::STATUS_PENDING,
                    'debit_account_id' => $cogsAccount?->id,
                    'credit_account_id' => $apAccount?->id,
                    'created_by' => Auth::id() ?? $crewUser->id,
                ]);
            }
        }

        return $entries;
    }

    /**
     * Create commission entries from a door knock (appointment set or sold).
     *
     * @return array<int, CommissionEntry>
     */
    public function createFromDoorKnock(DoorKnock $knock): array
    {
        // Only qualifying statuses trigger commissions
        if (! in_array($knock->status, ['appointment_set', 'sold'])) {
            return [];
        }

        $employee = $this->findEmployeeForUser($knock->user_id);
        if (! $employee) {
            return [];
        }

        // Prevent duplicates
        if ($this->hasDuplicateCommission($employee, $knock, 'door_knock')) {
            return [];
        }

        // Get revenue from linked deal if available
        $revenue = 0;
        if ($knock->deal_id) {
            $revenue = (float) ($knock->deal?->estimated_value ?? 0);
        }

        return $this->applyRulesForSource($employee, 'door_knock', $knock, $revenue, 0, autoOnly: true);
    }

    /**
     * Create territory override commissions by walking up the hierarchy.
     * Called when a job completes that originated from a door knock.
     *
     * @return array<int, CommissionEntry>
     */
    public function createTerritoryOverrideCommissions(Job $job): array
    {
        $deal = $job->deal;
        if (! $deal || ! $deal->origin_door_knock_id) {
            return [];
        }

        $knock = DoorKnock::find($deal->origin_door_knock_id);
        if (! $knock || ! $knock->territory_id) {
            return [];
        }

        $territory = SalesTerritory::find($knock->territory_id);
        if (! $territory) {
            return [];
        }

        $revenue = (float) ($job->estimate?->total ?? $deal->actual_value ?? $deal->estimated_value ?? 0);
        $entries = [];

        // Walk up the hierarchy and apply override commissions
        $ancestors = $territory->getAncestors();
        foreach ($ancestors as $ancestor) {
            $managerId = $ancestor->manager_id ?? $ancestor->assigned_user_id;
            if (! $managerId) {
                continue;
            }

            $employee = $this->findEmployeeForUser($managerId);
            if (! $employee) {
                continue;
            }

            // Prevent duplicates per manager per job
            if ($this->hasDuplicateCommission($employee, $job, 'territory_override')) {
                continue;
            }

            $overrideEntries = $this->applyRulesForSource(
                $employee, 'territory_override', $job, $revenue, 0, autoOnly: true
            );

            $entries = array_merge($entries, $overrideEntries);
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

            $entries[] = CommissionEntry::create(array_merge(
                $this->commissionableFieldsFor($employee),
                [
                    'source_type' => get_class($sourceModel),
                    'source_id' => $sourceModel->getKey(),
                    'source_label' => $this->buildSourceLabel($source, $sourceModel),
                    'commission_type' => $rule['type'],
                    'basis_amount' => $this->getBasisAmount($rule, $revenue, $profit),
                    'rate' => $rule['rate'] ?? 0,
                    'amount' => $amount,
                    'status' => 'pending',
                    'earned_date' => now()->toDateString(),
                ]
            ));
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
        return CommissionEntry::create(array_merge(
            $this->commissionableFieldsFor($employee),
            [
                'commission_type' => $type,
                'basis_amount' => $basisAmount ?? 0,
                'rate' => $rate ?? 0,
                'amount' => round($amount, 2),
                'status' => 'pending',
                'earned_date' => now()->toDateString(),
                'source_label' => 'Manual Entry',
                'notes' => $notes,
            ]
        ));
    }

    /**
     * Build the commissionable fields array for dual-write.
     * Sets both the polymorphic columns and (when applicable) the legacy employee_profile_id.
     *
     * @return array{commissionable_type: string, commissionable_id: int, employee_profile_id: int|null}
     */
    public function commissionableFieldsFor(Model $commissionable, ?int $employeeProfileId = null): array
    {
        $fields = [
            'commissionable_type' => get_class($commissionable),
            'commissionable_id' => $commissionable->getKey(),
        ];

        // If the commissionable IS an EmployeeProfile, also set legacy column
        if ($commissionable instanceof EmployeeProfile) {
            $fields['employee_profile_id'] = $commissionable->getKey();
        } elseif ($employeeProfileId) {
            $fields['employee_profile_id'] = $employeeProfileId;
        }

        return $fields;
    }

    /**
     * Apply commission rules from the commission_rules table for any commissionable entity.
     *
     * @return array<int, CommissionEntry>
     */
    public function applyRulesForCommissionable(
        Model $commissionable,
        string $source,
        Model $sourceModel,
        float $revenue = 0,
        float $profit = 0,
        bool $autoOnly = false,
        ?int $employeeProfileId = null
    ): array {
        if (! method_exists($commissionable, 'getTableCommissionRulesForSource')) {
            return [];
        }

        $rules = $commissionable->getTableCommissionRulesForSource($source, $autoOnly);
        $entries = [];

        foreach ($rules as $rule) {
            // Evaluate conditions if present
            if (! empty($rule['conditions']) && ! $this->evaluateConditions($rule['conditions'], $sourceModel)) {
                continue;
            }

            $amount = $this->calculateAmountFromRuleData($rule, $revenue, $profit);

            if ($amount <= 0) {
                continue;
            }

            $entries[] = CommissionEntry::create(array_merge(
                $this->commissionableFieldsFor($commissionable, $employeeProfileId),
                [
                    'source_type' => get_class($sourceModel),
                    'source_id' => $sourceModel->getKey(),
                    'source_label' => $this->buildSourceLabel($source, $sourceModel),
                    'commission_type' => $rule['type'],
                    'basis_amount' => $this->getBasisAmount($rule, $revenue, $profit),
                    'rate' => $rule['rate'] ?? 0,
                    'amount' => $amount,
                    'status' => 'pending',
                    'earned_date' => now()->toDateString(),
                ]
            ));
        }

        return $entries;
    }

    /**
     * Check if a commission entry already exists for a commissionable + source combo.
     */
    public function hasDuplicateCommissionForCommissionable(Model $commissionable, Model $sourceModel): bool
    {
        return CommissionEntry::query()
            ->where('commissionable_type', get_class($commissionable))
            ->where('commissionable_id', $commissionable->getKey())
            ->where('source_type', get_class($sourceModel))
            ->where('source_id', $sourceModel->getKey())
            ->whereNotIn('status', ['reversed'])
            ->exists();
    }

    /**
     * Evaluate conditions array against a source model.
     * Returns true if ALL conditions pass (AND logic), or if conditions are empty/null.
     */
    public function evaluateConditions(array $conditions, Model $sourceModel): bool
    {
        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'equals';
            $expected = $condition['value'] ?? null;
            $actual = data_get($sourceModel, $field);

            $passes = match ($operator) {
                'equals' => $actual == $expected,
                'not_equals' => $actual != $expected,
                'greater_than' => (float) $actual > (float) $expected,
                'less_than' => (float) $actual < (float) $expected,
                'in' => in_array($actual, (array) $expected),
                'is_empty' => empty($actual),
                'is_not_empty' => ! empty($actual),
                'contains' => is_string($actual) && str_contains($actual, (string) $expected),
                default => true,
            };

            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate commission amount from rule data without requiring an EmployeeProfile.
     * Handles flat, revenue_percentage, and profit_percentage types.
     * Tiered is not supported for generic commissionables (returns 0).
     */
    protected function calculateAmountFromRuleData(array $rule, float $revenue, float $profit): float
    {
        $type = $rule['type'] ?? 'flat';
        $rate = (float) ($rule['rate'] ?? 0);
        $amount = (float) ($rule['amount'] ?? 0);

        return match ($type) {
            'revenue_percentage' => round($revenue * $rate, 2),
            'profit_percentage' => round($profit * $rate, 2),
            'flat' => round($amount, 2),
            default => 0,
        };
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
            'door_knock' => 'Door Knock: '.($sourceModel->address ?? "#{$sourceModel->id}"),
            'territory_override' => 'Territory Override: '.($sourceModel->title ?? "#{$sourceModel->id}"),
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
