<?php

namespace App\Livewire\Agent;

use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Dashboard extends Component
{
    public function render(): View
    {
        $agent = auth('agent')->user();
        $agentProfile = $agent->agentProfile;
        $commissionRate = $agentProfile?->commission_rate ?? 10;

        // Existing Stats
        $totalListings = AgentListing::query()
            ->forAgent($agent)
            ->count();

        $activeListings = AgentListing::query()
            ->forAgent($agent)
            ->active()
            ->count();

        $referredCustomers = Customer::query()
            ->referredByAgent($agent)
            ->count();

        $upcomingOpenHousesCount = AgentListing::query()
            ->forAgent($agent)
            ->withUpcomingOpenHouse()
            ->where('open_house_date', '<=', now()->addDays(7))
            ->count();

        // Commission Stats
        $commissionStats = $this->calculateCommissionStats($agent, $commissionRate);

        // Performance Metrics
        $performanceMetrics = $this->calculatePerformanceMetrics($agent);

        // Deals by Stage
        $dealsByStage = $this->getDealsByStage($agent);

        // Monthly Deal Counts (last 6 months)
        $monthlyDealCounts = $this->getMonthlyDealCounts($agent);

        // Sections
        $upcomingOpenHouses = AgentListing::query()
            ->forAgent($agent)
            ->withUpcomingOpenHouse()
            ->orderBy('open_house_date')
            ->limit(5)
            ->get();

        $recentCustomers = Customer::query()
            ->referredByAgent($agent)
            ->with('listing')
            ->latest()
            ->limit(5)
            ->get();

        $recentDeals = Deal::query()
            ->forAgent($agent)
            ->with(['customer', 'property'])
            ->latest()
            ->limit(5)
            ->get();

        return view('livewire.agent.dashboard', [
            'totalListings' => $totalListings,
            'activeListings' => $activeListings,
            'referredCustomers' => $referredCustomers,
            'upcomingOpenHousesCount' => $upcomingOpenHousesCount,
            'upcomingOpenHouses' => $upcomingOpenHouses,
            'recentCustomers' => $recentCustomers,
            'commissionStats' => $commissionStats,
            'performanceMetrics' => $performanceMetrics,
            'dealsByStage' => $dealsByStage,
            'monthlyDealCounts' => $monthlyDealCounts,
            'recentDeals' => $recentDeals,
            'commissionRate' => $commissionRate,
        ])->layout('components.layouts.agent', ['title' => 'Dashboard']);
    }

    /**
     * Calculate commission statistics for the agent.
     *
     * @return array{total_earned: float, pending: float, this_month: float}
     */
    public function calculateCommissionStats($agent, float $commissionRate): array
    {
        // Total earned commissions (completed or paid deals)
        $earnedDealsValue = Deal::query()
            ->forAgent($agent)
            ->whereIn('stage', ['completed', 'paid'])
            ->sum(DB::raw('COALESCE(actual_value, estimated_value, 0)'));

        $totalEarned = $earnedDealsValue * ($commissionRate / 100);

        // Pending commissions (active deals not yet completed/paid/cancelled)
        $pendingDealsValue = Deal::query()
            ->forAgent($agent)
            ->whereNotIn('stage', ['completed', 'paid', 'cancelled'])
            ->sum(DB::raw('COALESCE(estimated_value, 0)'));

        $pendingCommission = $pendingDealsValue * ($commissionRate / 100);

        // This month's earned commissions
        $thisMonthDealsValue = Deal::query()
            ->forAgent($agent)
            ->whereIn('stage', ['completed', 'paid'])
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum(DB::raw('COALESCE(actual_value, estimated_value, 0)'));

        $thisMonthCommission = $thisMonthDealsValue * ($commissionRate / 100);

        return [
            'total_earned' => (float) $totalEarned,
            'pending' => (float) $pendingCommission,
            'this_month' => (float) $thisMonthCommission,
        ];
    }

    /**
     * Calculate performance metrics for the agent.
     *
     * @return array{conversion_rate: float, average_deal_value: float, total_referral_value: float}
     */
    public function calculatePerformanceMetrics($agent): array
    {
        $totalDeals = Deal::query()->forAgent($agent)->count();
        $wonDeals = Deal::query()->forAgent($agent)->won()->count();

        $conversionRate = $totalDeals > 0 ? ($wonDeals / $totalDeals) * 100 : 0;

        $avgDealValue = Deal::query()
            ->forAgent($agent)
            ->avg(DB::raw('COALESCE(actual_value, estimated_value, 0)')) ?? 0;

        $totalReferralValue = Deal::query()
            ->forAgent($agent)
            ->sum(DB::raw('COALESCE(estimated_value, 0)'));

        return [
            'conversion_rate' => round((float) $conversionRate, 1),
            'average_deal_value' => (float) $avgDealValue,
            'total_referral_value' => (float) $totalReferralValue,
        ];
    }

    /**
     * Get deal counts grouped by stage.
     *
     * @return array<string, int>
     */
    public function getDealsByStage($agent): array
    {
        $stages = array_keys(Deal::STAGES);
        $counts = [];

        foreach ($stages as $stage) {
            $counts[$stage] = Deal::query()
                ->forAgent($agent)
                ->where('stage', $stage)
                ->count();
        }

        return $counts;
    }

    /**
     * Get monthly deal counts for the last 6 months.
     *
     * @return array<string, int>
     */
    public function getMonthlyDealCounts($agent): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthKey = $date->format('M Y');

            $count = Deal::query()
                ->forAgent($agent)
                ->whereMonth('created_at', $date->month)
                ->whereYear('created_at', $date->year)
                ->count();

            $months[$monthKey] = $count;
        }

        return $months;
    }

    /**
     * Get badge color for a deal stage.
     */
    public function getStageBadgeColor(string $stage): string
    {
        return match ($stage) {
            'lead' => 'blue',
            'quoted' => 'purple',
            'scheduled' => 'yellow',
            'in_progress' => 'orange',
            'completed' => 'green',
            'paid' => 'emerald',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }
}
