<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use Carbon\CarbonInterface;

class TerritoryPnlService
{
    /**
     * Calculate P&L for a single territory (no rollup from children).
     */
    public function calculatePnl(SalesTerritory $territory, CarbonInterface $start, CarbonInterface $end): array
    {
        $revenue = $this->getRevenue($territory, $start, $end);
        $commissionCosts = $this->getCommissionCosts($territory, $start, $end);

        $budget = TerritoryBudget::where('territory_id', $territory->id)
            ->where('period_start', '<=', $end)
            ->where('period_start', '>=', $start->copy()->startOfMonth())
            ->first();

        $marketingCost = (float) ($budget?->marketing_budget ?? 0);
        $budgetTargetPct = (float) ($budget?->commission_budget_pct ?? 12);

        $totalCosts = $commissionCosts + $marketingCost;
        $margin = $revenue - $totalCosts;
        $budgetPct = $revenue > 0 ? round(($commissionCosts / $revenue) * 100, 1) : 0;

        return [
            'territory_id' => $territory->id,
            'territory_name' => $territory->name,
            'level' => $territory->level,
            'revenue' => $revenue,
            'commission_costs' => $commissionCosts,
            'marketing_costs' => $marketingCost,
            'total_costs' => $totalCosts,
            'margin' => $margin,
            'budget_pct' => $budgetPct,
            'budget_target_pct' => $budgetTargetPct,
            'within_budget' => $budgetPct <= $budgetTargetPct,
        ];
    }

    /**
     * Calculate P&L including all child territories (rollup).
     */
    public function calculatePnlRollup(SalesTerritory $territory, CarbonInterface $start, CarbonInterface $end): array
    {
        $territory->load('children.children');
        $allIds = array_merge([$territory->id], $territory->getDescendantIds());

        $revenue = 0;
        $commissionCosts = 0;
        $marketingCosts = 0;

        foreach ($allIds as $id) {
            $t = SalesTerritory::find($id);
            if (! $t) {
                continue;
            }
            $revenue += $this->getRevenue($t, $start, $end);
            $commissionCosts += $this->getCommissionCosts($t, $start, $end);

            $budget = TerritoryBudget::where('territory_id', $id)
                ->where('period_start', '<=', $end)
                ->where('period_start', '>=', $start->copy()->startOfMonth())
                ->first();
            $marketingCosts += (float) ($budget?->marketing_budget ?? 0);
        }

        $totalCosts = $commissionCosts + $marketingCosts;
        $margin = $revenue - $totalCosts;
        $budgetPct = $revenue > 0 ? round(($commissionCosts / $revenue) * 100, 1) : 0;

        return [
            'territory_id' => $territory->id,
            'territory_name' => $territory->name,
            'level' => $territory->level,
            'revenue' => $revenue,
            'commission_costs' => $commissionCosts,
            'marketing_costs' => $marketingCosts,
            'total_costs' => $totalCosts,
            'margin' => $margin,
            'budget_pct' => $budgetPct,
            'budget_target_pct' => 12.0,
            'within_budget' => $budgetPct <= 12.0,
        ];
    }

    /**
     * Get budget alert if trending over target.
     */
    public function getBudgetAlert(SalesTerritory $territory, CarbonInterface $start, CarbonInterface $end): ?array
    {
        $pnl = $this->calculatePnl($territory, $start, $end);

        if (! $pnl['within_budget']) {
            return [
                'territory' => $territory->name,
                'current_pct' => $pnl['budget_pct'],
                'target_pct' => $pnl['budget_target_pct'],
                'over_by' => round($pnl['budget_pct'] - $pnl['budget_target_pct'], 1),
            ];
        }

        return null;
    }

    private function getRevenue(SalesTerritory $territory, CarbonInterface $start, CarbonInterface $end): float
    {
        $knockIds = DoorKnock::where('territory_id', $territory->id)
            ->whereBetween('knocked_at', [$start, $end])
            ->pluck('id');

        return (float) Deal::whereIn('origin_door_knock_id', $knockIds)
            ->where('stage', 'completed')
            ->sum('actual_value');
    }

    private function getCommissionCosts(SalesTerritory $territory, CarbonInterface $start, CarbonInterface $end): float
    {
        $knockIds = DoorKnock::where('territory_id', $territory->id)
            ->pluck('id');

        return (float) CommissionEntry::where('source_type', DoorKnock::class)
            ->whereIn('source_id', $knockIds)
            ->whereIn('status', ['pending', 'approved', 'paid'])
            ->whereBetween('earned_date', [$start, $end])
            ->sum('amount');
    }
}
