<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\CrewProfile;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class OwnerDashboardService
{
    /**
     * Get urgent alerts needing attention.
     *
     * @return Collection<int, array{type: string, severity: string, message: string, count: int, route: string, icon: string}>
     */
    public function getAlerts(): Collection
    {
        return Cache::remember('dashboard.alerts', 300, function () {
            $alerts = collect();

            // Overdue invoices - consolidated into single query
            $overdueCount = Invoice::where(function ($q) {
                $q->where('status', Invoice::STATUS_OVERDUE)
                    ->orWhere(function ($q2) {
                        $q2->where('status', Invoice::STATUS_SENT)
                            ->where('due_date', '<', now());
                    });
            })->count();

            if ($overdueCount > 0) {
                $alerts->push([
                    'type' => 'overdue_invoices',
                    'severity' => 'red',
                    'message' => "{$overdueCount} overdue invoice(s)",
                    'count' => $overdueCount,
                    'route' => 'invoices.index',
                    'icon' => 'exclamation-triangle',
                ]);
            }

            // Pending payable approvals
            $pendingPayables = JobPayable::pending()->count();
            if ($pendingPayables > 0) {
                $alerts->push([
                    'type' => 'pending_payables',
                    'severity' => 'amber',
                    'message' => "{$pendingPayables} payable(s) awaiting approval",
                    'count' => $pendingPayables,
                    'route' => 'ar.index',
                    'icon' => 'banknotes',
                ]);
            }

            // Pending commission approvals
            $pendingCommissions = CommissionEntry::pending()->count();
            if ($pendingCommissions > 0) {
                $alerts->push([
                    'type' => 'pending_commissions',
                    'severity' => 'amber',
                    'message' => "{$pendingCommissions} commission(s) awaiting approval",
                    'count' => $pendingCommissions,
                    'route' => 'payroll.commissions',
                    'icon' => 'currency-dollar',
                ]);
            }

            // Expired crew insurance/compliance
            $complianceIssues = CrewProfile::query()
                ->whereHas('user', fn ($q) => $q->where('is_active', true))
                ->get()
                ->filter(fn ($cp) => $cp->hasComplianceWarnings())
                ->count();
            if ($complianceIssues > 0) {
                $alerts->push([
                    'type' => 'crew_compliance',
                    'severity' => 'red',
                    'message' => "{$complianceIssues} crew(s) with compliance warnings",
                    'count' => $complianceIssues,
                    'route' => 'crews.index',
                    'icon' => 'shield-exclamation',
                ]);
            }

            return $alerts;
        });
    }

    /**
     * Get cash flow snapshot.
     *
     * @return array{outstanding_ar: float, outstanding_ap: float, collected_mtd: float, collected_ytd: float, paid_out_mtd: float, net_mtd: float}
     */
    public function getCashFlowSnapshot(): array
    {
        return Cache::remember('dashboard.cash_flow', 300, function () {
            $collectedMtd = (float) Payment::where('status', 'completed')
                ->whereMonth('received_at', now()->month)
                ->whereYear('received_at', now()->year)
                ->sum('amount');

            $paidOutMtd = (float) JobPayable::paid()
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('amount');

            return [
                'outstanding_ar' => (float) Invoice::whereIn('status', ['sent', 'partially_paid', 'overdue'])
                    ->sum('balance_due'),
                'outstanding_ap' => (float) JobPayable::whereIn('status', ['pending', 'approved'])
                    ->sum('amount'),
                'collected_mtd' => $collectedMtd,
                'collected_ytd' => (float) Payment::where('status', 'completed')
                    ->whereYear('received_at', now()->year)
                    ->sum('amount'),
                'paid_out_mtd' => $paidOutMtd,
                'net_mtd' => $collectedMtd - $paidOutMtd,
            ];
        });
    }

    /**
     * Get pipeline snapshot.
     *
     * @return array{stages: array<string, int>, total_pipeline_value: float, close_rate: float}
     */
    public function getPipelineSnapshot(): array
    {
        return Cache::remember('dashboard.pipeline', 300, function () {
            // Combine stage counts and pipeline value into one query
            $pipelineData = Deal::query()
                ->active()
                ->selectRaw('stage, COUNT(*) as count, SUM(estimated_value) as total_value')
                ->groupBy('stage')
                ->get();

            $stages = $pipelineData->pluck('count', 'stage')->toArray();
            $pipelineValue = (float) $pipelineData->sum('total_value');

            // Calculate close rate (last 90 days) - consolidated into single query
            $closeRateData = Deal::where('updated_at', '>=', now()->subDays(90))
                ->whereIn('stage', ['signed', 'completed', 'lost', 'cancelled'])
                ->selectRaw("COUNT(*) as total, SUM(CASE WHEN stage IN ('signed', 'completed') THEN 1 ELSE 0 END) as won")
                ->first();

            $recent = (int) $closeRateData->total;
            $won = (int) $closeRateData->won;

            return [
                'stages' => $stages,
                'total_pipeline_value' => $pipelineValue,
                'close_rate' => $recent > 0 ? round(($won / $recent) * 100, 1) : 0,
            ];
        });
    }

    /**
     * Get jobs snapshot.
     *
     * @return array{by_stage: array<string, int>, active_count: int, overdue: int, upcoming_7_days: int}
     */
    public function getJobsSnapshot(): array
    {
        return Cache::remember('dashboard.jobs', 300, function () {
            // Get all stats in one query using conditional aggregation
            $stats = Job::query()
                ->where('stage', '!=', 'completed')
                ->selectRaw('stage, COUNT(*) as count')
                ->selectRaw('SUM(CASE WHEN scheduled_date < ? THEN 1 ELSE 0 END) as overdue', [now()])
                ->selectRaw('SUM(CASE WHEN scheduled_date BETWEEN ? AND ? THEN 1 ELSE 0 END) as upcoming', [now(), now()->addDays(7)])
                ->groupBy('stage')
                ->get();

            $byStage = $stats->pluck('count', 'stage')->toArray();

            return [
                'by_stage' => $byStage,
                'active_count' => array_sum($byStage),
                'overdue' => (int) $stats->sum('overdue'),
                'upcoming_7_days' => (int) $stats->sum('upcoming'),
            ];
        });
    }
}
