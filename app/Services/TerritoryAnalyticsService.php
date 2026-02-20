<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TerritoryAnalyticsService
{
    /**
     * Base query with filters applied.
     */
    public function baseQuery(array $filters): Builder
    {
        return DoorKnock::query()
            ->when($filters['dateFrom'] ?? null, fn ($q, $v) => $q->where('knocked_at', '>=', $v))
            ->when($filters['dateTo'] ?? null, fn ($q, $v) => $q->where('knocked_at', '<=', $v.' 23:59:59'))
            ->when($filters['userId'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['territoryId'] ?? null, fn ($q, $v) => $q->where('territory_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v));
    }

    /**
     * Summary KPIs in a single aggregate query.
     */
    public function getSummaryStats(array $filters): object
    {
        $result = $this->baseQuery($filters)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COUNT(DISTINCT address) as unique_addresses')
            ->selectRaw("SUM(CASE WHEN status IN ('interested', 'appointment_set', 'sold') THEN 1 ELSE 0 END) as conversions")
            ->selectRaw("SUM(CASE WHEN status IN ('appointment_set', 'sold') THEN 1 ELSE 0 END) as appointments")
            ->selectRaw("SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold")
            ->selectRaw("SUM(CASE WHEN status = 'not_home' THEN 1 ELSE 0 END) as not_home")
            ->selectRaw("SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) as interested")
            ->selectRaw("SUM(CASE WHEN status = 'not_interested' THEN 1 ELSE 0 END) as not_interested")
            ->selectRaw("SUM(CASE WHEN status = 'appointment_set' THEN 1 ELSE 0 END) as appointment_set")
            ->selectRaw("SUM(CASE WHEN status = 'come_back' THEN 1 ELSE 0 END) as come_back")
            ->first();

        return $result ?? (object) [
            'total' => 0, 'unique_addresses' => 0, 'conversions' => 0,
            'appointments' => 0, 'sold' => 0, 'not_home' => 0,
            'interested' => 0, 'not_interested' => 0, 'appointment_set' => 0,
            'come_back' => 0,
        ];
    }

    /**
     * Conversion funnel: Knocks -> Interested -> Appointments -> Deals -> Signed.
     */
    public function getConversionFunnel(array $filters): array
    {
        $stats = $this->getSummaryStats($filters);

        $knockIds = $this->baseQuery($filters)->whereNotNull('deal_id')->pluck('deal_id')->unique();
        $dealStats = Deal::whereIn('id', $knockIds)
            ->selectRaw('COUNT(*) as total_deals')
            ->selectRaw("SUM(CASE WHEN stage = 'signed' THEN 1 ELSE 0 END) as signed_deals")
            ->selectRaw("SUM(CASE WHEN stage = 'completed' THEN 1 ELSE 0 END) as completed_deals")
            ->first();

        $totalKnocks = (int) $stats->total;
        $interested = (int) $stats->interested + (int) $stats->appointment_set + (int) $stats->sold;
        $appointments = (int) $stats->appointment_set + (int) $stats->sold;
        $deals = (int) ($dealStats->total_deals ?? 0);
        $signed = (int) ($dealStats->signed_deals ?? 0) + (int) ($dealStats->completed_deals ?? 0);

        return [
            ['label' => 'Total Knocks', 'count' => $totalKnocks, 'pct' => 100],
            ['label' => 'Interested', 'count' => $interested, 'pct' => $totalKnocks > 0 ? round(($interested / $totalKnocks) * 100, 1) : 0],
            ['label' => 'Appointments', 'count' => $appointments, 'pct' => $totalKnocks > 0 ? round(($appointments / $totalKnocks) * 100, 1) : 0],
            ['label' => 'Deals Created', 'count' => $deals, 'pct' => $totalKnocks > 0 ? round(($deals / $totalKnocks) * 100, 1) : 0],
            ['label' => 'Signed/Completed', 'count' => $signed, 'pct' => $totalKnocks > 0 ? round(($signed / $totalKnocks) * 100, 1) : 0],
        ];
    }

    /**
     * Revenue from linked deals.
     */
    public function getRevenueStats(array $filters): object
    {
        $dealIds = $this->baseQuery($filters)->whereNotNull('deal_id')->pluck('deal_id')->unique();

        if ($dealIds->isEmpty()) {
            return (object) [
                'total_estimated' => 0, 'total_actual' => 0,
                'deal_count' => 0, 'signed_count' => 0, 'avg_deal_value' => 0,
            ];
        }

        return Deal::whereIn('id', $dealIds)
            ->selectRaw('SUM(estimated_value) as total_estimated')
            ->selectRaw('SUM(actual_value) as total_actual')
            ->selectRaw('COUNT(*) as deal_count')
            ->selectRaw("SUM(CASE WHEN stage IN ('signed', 'completed') THEN 1 ELSE 0 END) as signed_count")
            ->selectRaw('AVG(estimated_value) as avg_deal_value')
            ->first();
    }

    /**
     * Per-user performance breakdown.
     */
    public function getUserPerformance(array $filters): Collection
    {
        $rows = $this->baseQuery($filters)
            ->select('user_id')
            ->selectRaw('COUNT(*) as total_knocks')
            ->selectRaw("SUM(CASE WHEN status IN ('interested', 'appointment_set', 'sold') THEN 1 ELSE 0 END) as conversions")
            ->selectRaw("SUM(CASE WHEN status IN ('appointment_set', 'sold') THEN 1 ELSE 0 END) as appointments")
            ->selectRaw("SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as deals")
            ->groupBy('user_id')
            ->orderByDesc('total_knocks')
            ->get();

        $userIds = $rows->pluck('user_id')->filter()->all();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $revenueByUser = DoorKnock::query()
            ->whereNotNull('deal_id')
            ->whereIn('user_id', $userIds)
            ->when($filters['dateFrom'] ?? null, fn ($q, $v) => $q->where('knocked_at', '>=', $v))
            ->when($filters['dateTo'] ?? null, fn ($q, $v) => $q->where('knocked_at', '<=', $v.' 23:59:59'))
            ->when($filters['territoryId'] ?? null, fn ($q, $v) => $q->where('territory_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->join('deals', 'door_knocks.deal_id', '=', 'deals.id')
            ->select('door_knocks.user_id')
            ->selectRaw('SUM(deals.estimated_value) as revenue')
            ->groupBy('door_knocks.user_id')
            ->pluck('revenue', 'user_id');

        return $rows->map(fn ($row) => (object) [
            'user' => $users->get($row->user_id),
            'total_knocks' => (int) $row->total_knocks,
            'conversions' => (int) $row->conversions,
            'appointments' => (int) $row->appointments,
            'deals' => (int) $row->deals,
            'revenue' => (float) ($revenueByUser[$row->user_id] ?? 0),
            'conversion_rate' => $row->total_knocks > 0 ? round(($row->conversions / $row->total_knocks) * 100, 1) : 0,
        ]);
    }

    /**
     * Per-territory performance breakdown.
     */
    public function getTerritoryPerformance(array $filters): Collection
    {
        $rows = $this->baseQuery($filters)
            ->select('territory_id')
            ->selectRaw('COUNT(*) as total_knocks')
            ->selectRaw('COUNT(DISTINCT address) as unique_addresses')
            ->selectRaw("SUM(CASE WHEN status IN ('interested', 'appointment_set', 'sold') THEN 1 ELSE 0 END) as conversions")
            ->groupBy('territory_id')
            ->orderByDesc('total_knocks')
            ->get();

        $territoryIds = $rows->pluck('territory_id')->filter()->all();
        $territories = SalesTerritory::whereIn('id', $territoryIds)->get()->keyBy('id');

        $revenueByTerritory = DoorKnock::query()
            ->whereNotNull('deal_id')
            ->whereIn('territory_id', $territoryIds)
            ->when($filters['dateFrom'] ?? null, fn ($q, $v) => $q->where('knocked_at', '>=', $v))
            ->when($filters['dateTo'] ?? null, fn ($q, $v) => $q->where('knocked_at', '<=', $v.' 23:59:59'))
            ->when($filters['userId'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->join('deals', 'door_knocks.deal_id', '=', 'deals.id')
            ->select('door_knocks.territory_id')
            ->selectRaw('SUM(deals.estimated_value) as revenue')
            ->groupBy('door_knocks.territory_id')
            ->pluck('revenue', 'territory_id');

        return $rows->map(fn ($row) => (object) [
            'territory' => $territories->get($row->territory_id),
            'total_knocks' => (int) $row->total_knocks,
            'unique_addresses' => (int) $row->unique_addresses,
            'conversions' => (int) $row->conversions,
            'revenue' => (float) ($revenueByTerritory[$row->territory_id] ?? 0),
            'conversion_rate' => $row->total_knocks > 0 ? round(($row->conversions / $row->total_knocks) * 100, 1) : 0,
        ]);
    }

    /**
     * Daily trend: knocks per day.
     */
    public function getDailyTrend(array $filters): Collection
    {
        return $this->baseQuery($filters)
            ->selectRaw('DATE(knocked_at) as date')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date');
    }

    /**
     * Hourly distribution: knocks by hour of day.
     */
    public function getHourlyDistribution(array $filters): Collection
    {
        $hourExpr = DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', knocked_at) AS INTEGER)"
            : 'HOUR(knocked_at)';

        return $this->baseQuery($filters)
            ->selectRaw("{$hourExpr} as hour")
            ->selectRaw('COUNT(*) as count')
            ->groupBy('hour')
            ->orderBy('hour')
            ->pluck('count', 'hour');
    }

    /**
     * User detail drill-down.
     */
    public function getUserDetail(int $userId, array $filters): array
    {
        $userFilters = array_merge($filters, ['userId' => $userId]);
        $stats = $this->getSummaryStats($userFilters);
        $revenue = $this->getRevenueStats($userFilters);

        $territories = $this->baseQuery($userFilters)
            ->select('territory_id')
            ->selectRaw('COUNT(*) as knock_count')
            ->groupBy('territory_id')
            ->orderByDesc('knock_count')
            ->get();

        $territoryIds = $territories->pluck('territory_id')->filter()->all();
        $territoryModels = SalesTerritory::whereIn('id', $territoryIds)->get()->keyBy('id');

        $territoriesWorked = $territories->map(fn ($row) => (object) [
            'territory' => $territoryModels->get($row->territory_id),
            'knock_count' => (int) $row->knock_count,
        ]);

        $bestDay = $this->baseQuery($userFilters)
            ->selectRaw('DATE(knocked_at) as date')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('date')
            ->orderByDesc('count')
            ->first();

        $daysActive = $this->baseQuery($userFilters)
            ->selectRaw('COUNT(DISTINCT DATE(knocked_at)) as days')
            ->value('days');

        return [
            'stats' => $stats,
            'revenue' => $revenue,
            'territories' => $territoriesWorked,
            'best_day' => $bestDay ? ['date' => $bestDay->date, 'count' => (int) $bestDay->count] : null,
            'days_active' => (int) $daysActive,
            'daily_average' => $daysActive > 0 ? round($stats->total / $daysActive, 1) : 0,
        ];
    }

    /**
     * Territory detail drill-down.
     */
    public function getTerritoryDetail(int $territoryId, array $filters): array
    {
        $territoryFilters = array_merge($filters, ['territoryId' => $territoryId]);
        $stats = $this->getSummaryStats($territoryFilters);
        $revenue = $this->getRevenueStats($territoryFilters);

        $reps = $this->baseQuery($territoryFilters)
            ->select('user_id')
            ->selectRaw('COUNT(*) as knock_count')
            ->selectRaw("SUM(CASE WHEN status IN ('interested', 'appointment_set', 'sold') THEN 1 ELSE 0 END) as conversions")
            ->groupBy('user_id')
            ->orderByDesc('knock_count')
            ->get();

        $userIds = $reps->pluck('user_id')->filter()->all();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $repPerformance = $reps->map(fn ($row) => (object) [
            'user' => $users->get($row->user_id),
            'knock_count' => (int) $row->knock_count,
            'conversions' => (int) $row->conversions,
            'conversion_rate' => $row->knock_count > 0 ? round(($row->conversions / $row->knock_count) * 100, 1) : 0,
        ]);

        $repeatVisits = $this->baseQuery($territoryFilters)
            ->selectRaw('address, COUNT(*) as visit_count')
            ->groupBy('address')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        return [
            'stats' => $stats,
            'revenue' => $revenue,
            'reps' => $repPerformance,
            'repeat_visits' => $repeatVisits,
        ];
    }

    /**
     * Paginated customer/knock list.
     */
    public function getCustomerList(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->baseQuery($filters)
            ->with(['user:id,name', 'territory:id,name,color', 'deal:id,stage,estimated_value'])
            ->orderByDesc('knocked_at')
            ->paginate($perPage);
    }
}
