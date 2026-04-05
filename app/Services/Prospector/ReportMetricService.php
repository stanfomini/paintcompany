<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Call;

class ReportMetricService
{
    /**
     * Available metrics users can chart.
     */
    public static function availableMetrics(): array
    {
        return [
            'call_count' => 'Total Calls',
            'connected_count' => 'Connected Calls',
            'booked_count' => 'Booked / Interested',
            'booking_rate' => 'Booking Rate (%)',
            'connect_rate' => 'Connect Rate (%)',
            'total_talk_time' => 'Total Talk Time (min)',
            'avg_talk_time' => 'Avg Talk Time (sec)',
            'avg_duration' => 'Avg Call Duration (sec)',
            'unique_contacts' => 'Unique Contacts',
            'voicemail_count' => 'Voicemails',
            'dnc_count' => 'DNC Count',
        ];
    }

    /**
     * Available dimensions for X-axis / grouping.
     */
    public static function availableDimensions(): array
    {
        return [
            'date' => 'Date',
            'hour' => 'Hour of Day',
            'day_of_week' => 'Day of Week',
            'week' => 'Week',
            'month' => 'Month',
            'agent' => 'Agent',
            'campaign' => 'Campaign',
            'company' => 'Company',
            'disposition' => 'Disposition',
            'amd_result' => 'AMD Result',
            'direction' => 'Direction',
            'contact_source' => 'Contact Source',
            'contact_timezone' => 'Contact Timezone',
            'contact_state' => 'Contact State',
            'pipeline_stage' => 'Pipeline Stage',
            'duration_bucket' => 'Call Duration Range',
            'attempt_bucket' => 'Attempt Count Range',
        ];
    }

    /**
     * Run a chart query and return Chart.js-compatible data.
     *
     * @param  array  $config  {metric, dimension, group_by, filters}
     * @return array  {labels, datasets, summary}
     */
    public function query(array $config): array
    {
        $metric = $config['metric'] ?? 'call_count';
        $dimension = $config['dimension'] ?? 'date';
        $groupBy = $config['group_by'] ?? null;
        $filters = $config['filters'] ?? [];

        // Build base query
        $query = $this->buildBaseQuery($filters);

        // Get dimension SQL expression and label resolver
        [$dimExpr, $dimAlias] = $this->getDimensionExpression($dimension);

        // Get metric SQL expression
        $metricExpr = $this->getMetricExpression($metric);

        if ($groupBy && $groupBy !== $dimension) {
            return $this->queryGrouped($query, $metric, $metricExpr, $dimExpr, $dimAlias, $dimension, $groupBy, $filters);
        }

        return $this->querySimple($query, $metric, $metricExpr, $dimExpr, $dimAlias, $dimension);
    }

    /**
     * Simple query: one dataset, grouped by dimension.
     */
    protected function querySimple($query, string $metric, string $metricExpr, string $dimExpr, string $dimAlias, string $dimension): array
    {
        if (in_array($metric, ['booking_rate', 'connect_rate'])) {
            return $this->queryRate($query, $metric, $dimExpr, $dimAlias, $dimension);
        }

        $results = (clone $query)
            ->selectRaw("{$dimExpr} as dim_value, {$metricExpr} as metric_value")
            ->groupBy('dim_value')
            ->orderBy('dim_value')
            ->get();

        $labels = $results->pluck('dim_value')->map(fn ($v) => $this->formatDimensionLabel($dimension, $v))->toArray();
        $data = $results->pluck('metric_value')->map(fn ($v) => round((float) $v, 1))->toArray();

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => self::availableMetrics()[$metric] ?? $metric, 'data' => $data],
            ],
            'summary' => [
                'total' => array_sum($data),
                'average' => count($data) > 0 ? round(array_sum($data) / count($data), 1) : 0,
                'max' => count($data) > 0 ? max($data) : 0,
                'min' => count($data) > 0 ? min($data) : 0,
            ],
        ];
    }

    /**
     * Grouped query: multiple datasets (one per group_by value).
     */
    protected function queryGrouped($query, string $metric, string $metricExpr, string $dimExpr, string $dimAlias, string $dimension, string $groupBy, array $filters): array
    {
        [$groupExpr, $groupAlias] = $this->getDimensionExpression($groupBy);

        if (in_array($metric, ['booking_rate', 'connect_rate'])) {
            return $this->queryGroupedRate($query, $metric, $dimExpr, $dimAlias, $dimension, $groupExpr, $groupBy);
        }

        $results = (clone $query)
            ->selectRaw("{$dimExpr} as dim_value, {$groupExpr} as group_value, {$metricExpr} as metric_value")
            ->groupBy('dim_value', 'group_value')
            ->orderBy('dim_value')
            ->get();

        $dimValues = $results->pluck('dim_value')->unique()->sort()->values();
        $groupValues = $results->pluck('group_value')->unique()->sort()->values();

        $labels = $dimValues->map(fn ($v) => $this->formatDimensionLabel($dimension, $v))->toArray();

        $datasets = [];
        foreach ($groupValues as $gv) {
            $data = [];
            foreach ($dimValues as $dv) {
                $row = $results->first(fn ($r) => $r->dim_value == $dv && $r->group_value == $gv);
                $data[] = $row ? round((float) $row->metric_value, 1) : 0;
            }
            $datasets[] = [
                'label' => $this->formatDimensionLabel($groupBy, $gv),
                'data' => $data,
            ];
        }

        $allData = collect($datasets)->pluck('data')->flatten();

        return [
            'labels' => $labels,
            'datasets' => $datasets,
            'summary' => [
                'total' => $allData->sum(),
                'average' => $allData->count() > 0 ? round($allData->avg(), 1) : 0,
                'max' => $allData->max() ?? 0,
                'min' => $allData->min() ?? 0,
            ],
        ];
    }

    /**
     * Rate metric query (booking_rate, connect_rate).
     */
    protected function queryRate($query, string $metric, string $dimExpr, string $dimAlias, string $dimension): array
    {
        $numerator = $metric === 'booking_rate'
            ? "SUM(CASE WHEN prospector_calls.disposition IN ('booked','interested','appointment_set') THEN 1 ELSE 0 END)"
            : 'SUM(CASE WHEN prospector_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END)';

        $denominator = $metric === 'booking_rate'
            ? 'SUM(CASE WHEN prospector_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END)'
            : 'COUNT(*)';

        $results = (clone $query)
            ->selectRaw("{$dimExpr} as dim_value, {$numerator} as num, {$denominator} as den")
            ->groupBy('dim_value')
            ->orderBy('dim_value')
            ->get();

        $labels = $results->pluck('dim_value')->map(fn ($v) => $this->formatDimensionLabel($dimension, $v))->toArray();
        $data = $results->map(fn ($r) => $r->den > 0 ? round(($r->num / $r->den) * 100, 1) : 0)->toArray();

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => self::availableMetrics()[$metric] ?? $metric, 'data' => array_values($data)],
            ],
            'summary' => [
                'total' => count($data) > 0 ? round(array_sum($data) / count($data), 1) : 0,
                'average' => count($data) > 0 ? round(array_sum($data) / count($data), 1) : 0,
                'max' => count($data) > 0 ? max($data) : 0,
                'min' => count($data) > 0 ? min($data) : 0,
            ],
        ];
    }

    /**
     * Grouped rate metric query.
     */
    protected function queryGroupedRate($query, string $metric, string $dimExpr, string $dimAlias, string $dimension, string $groupExpr, string $groupBy): array
    {
        $numerator = $metric === 'booking_rate'
            ? "SUM(CASE WHEN prospector_calls.disposition IN ('booked','interested','appointment_set') THEN 1 ELSE 0 END)"
            : 'SUM(CASE WHEN prospector_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END)';

        $denominator = $metric === 'booking_rate'
            ? 'SUM(CASE WHEN prospector_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END)'
            : 'COUNT(*)';

        $results = (clone $query)
            ->selectRaw("{$dimExpr} as dim_value, {$groupExpr} as group_value, {$numerator} as num, {$denominator} as den")
            ->groupBy('dim_value', 'group_value')
            ->orderBy('dim_value')
            ->get();

        $dimValues = $results->pluck('dim_value')->unique()->sort()->values();
        $groupValues = $results->pluck('group_value')->unique()->sort()->values();

        $labels = $dimValues->map(fn ($v) => $this->formatDimensionLabel($dimension, $v))->toArray();

        $datasets = [];
        foreach ($groupValues as $gv) {
            $data = [];
            foreach ($dimValues as $dv) {
                $row = $results->first(fn ($r) => $r->dim_value == $dv && $r->group_value == $gv);
                $data[] = $row && $row->den > 0 ? round(($row->num / $row->den) * 100, 1) : 0;
            }
            $datasets[] = [
                'label' => $this->formatDimensionLabel($groupBy, $gv),
                'data' => $data,
            ];
        }

        return [
            'labels' => $labels,
            'datasets' => $datasets,
            'summary' => ['total' => 0, 'average' => 0, 'max' => 0, 'min' => 0],
        ];
    }

    /**
     * Build the base query with filters applied.
     */
    protected function buildBaseQuery(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        $query = Call::query()
            ->join('prospector_contacts', 'prospector_calls.contact_id', '=', 'prospector_contacts.id');

        // Date filters
        if (! empty($filters['date_range']) && $filters['date_range'] !== 'custom') {
            [$from, $to] = $this->resolveDateRange($filters['date_range']);
            $query->where('prospector_calls.started_at', '>=', $from)
                ->where('prospector_calls.started_at', '<=', $to);
        } else {
            if (! empty($filters['date_from'])) {
                $query->where('prospector_calls.started_at', '>=', $filters['date_from']);
            }
            if (! empty($filters['date_to'])) {
                $query->where('prospector_calls.started_at', '<=', $filters['date_to'].' 23:59:59');
            }
        }

        $companyIds = array_filter((array) ($filters['company_ids'] ?? []), fn ($v) => $v !== '' && $v !== null);
        if (! empty($companyIds)) {
            $query->whereIn('prospector_calls.company_id', $companyIds);
        }

        $campaignIds = array_filter((array) ($filters['campaign_ids'] ?? []), fn ($v) => $v !== '' && $v !== null);
        if (! empty($campaignIds)) {
            $query->whereIn('prospector_calls.campaign_id', $campaignIds);
        }

        $agentIds = array_filter((array) ($filters['agent_ids'] ?? []), fn ($v) => $v !== '' && $v !== null);
        if (! empty($agentIds)) {
            $query->whereIn('prospector_calls.agent_id', $agentIds);
        }

        $dispositions = array_filter((array) ($filters['dispositions'] ?? []), fn ($v) => $v !== '' && $v !== null);
        if (! empty($dispositions)) {
            $query->whereIn('prospector_calls.disposition', $dispositions);
        }
        if (! empty($filters['direction'])) {
            $query->where('prospector_calls.direction', $filters['direction']);
        }

        return $query;
    }

    /**
     * Resolve preset date ranges to from/to timestamps.
     */
    protected function resolveDateRange(string $preset): array
    {
        return match ($preset) {
            'today' => [today()->startOfDay(), today()->endOfDay()],
            'yesterday' => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfWeek()],
            'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()],
            'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'last_30_days' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'last_90_days' => [now()->subDays(90)->startOfDay(), now()->endOfDay()],
            default => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
        };
    }

    /**
     * Get SQL expression for a dimension.
     */
    protected function getDimensionExpression(string $dimension): array
    {
        return match ($dimension) {
            'date' => ['DATE(prospector_calls.started_at)', 'call_date'],
            'hour' => ['HOUR(prospector_calls.started_at)', 'call_hour'],
            'day_of_week' => ['DAYNAME(prospector_calls.started_at)', 'call_day'],
            'week' => ["DATE_FORMAT(prospector_calls.started_at, '%x-W%v')", 'call_week'],
            'month' => ["DATE_FORMAT(prospector_calls.started_at, '%Y-%m')", 'call_month'],
            'agent' => ['COALESCE((SELECT name FROM users WHERE users.id = (SELECT user_id FROM prospector_agents WHERE prospector_agents.id = prospector_calls.agent_id LIMIT 1)), "Unknown")', 'agent_name'],
            'campaign' => ['COALESCE((SELECT name FROM prospector_campaigns WHERE prospector_campaigns.id = prospector_calls.campaign_id), "No Campaign")', 'campaign_name'],
            'company' => ['COALESCE((SELECT name FROM prospector_companies WHERE prospector_companies.id = prospector_calls.company_id), "Unknown")', 'company_name'],
            'disposition' => ['COALESCE(prospector_calls.disposition, "none")', 'disposition'],
            'amd_result' => ['COALESCE(prospector_calls.amd_result, "none")', 'amd_result'],
            'direction' => ['prospector_calls.direction', 'direction'],
            'contact_source' => ['COALESCE(prospector_contacts.source, "unknown")', 'source'],
            'contact_timezone' => ['COALESCE(prospector_contacts.timezone, "unknown")', 'timezone'],
            'contact_state' => ['COALESCE(prospector_contacts.state, "unknown")', 'state'],
            'pipeline_stage' => ['COALESCE((SELECT name FROM prospector_pipeline_stages WHERE prospector_pipeline_stages.id = prospector_contacts.pipeline_stage_id), "None")', 'stage'],
            'duration_bucket' => ["CASE WHEN prospector_calls.duration_seconds < 30 THEN '0-30s' WHEN prospector_calls.duration_seconds < 60 THEN '30-60s' WHEN prospector_calls.duration_seconds < 300 THEN '1-5min' ELSE '5+min' END", 'duration_bucket'],
            'attempt_bucket' => ["CASE WHEN (SELECT total_attempts FROM prospector_campaign_contact WHERE prospector_campaign_contact.contact_id = prospector_calls.contact_id AND prospector_campaign_contact.campaign_id = prospector_calls.campaign_id LIMIT 1) <= 1 THEN '1' WHEN (SELECT total_attempts FROM prospector_campaign_contact WHERE prospector_campaign_contact.contact_id = prospector_calls.contact_id AND prospector_campaign_contact.campaign_id = prospector_calls.campaign_id LIMIT 1) <= 3 THEN '2-3' WHEN (SELECT total_attempts FROM prospector_campaign_contact WHERE prospector_campaign_contact.contact_id = prospector_calls.contact_id AND prospector_campaign_contact.campaign_id = prospector_calls.campaign_id LIMIT 1) <= 5 THEN '4-5' ELSE '6+' END", 'attempt_bucket'],
            default => ['DATE(prospector_calls.started_at)', 'call_date'],
        };
    }

    /**
     * Get SQL aggregate expression for a metric.
     */
    protected function getMetricExpression(string $metric): string
    {
        return match ($metric) {
            'call_count' => 'COUNT(*)',
            'connected_count' => 'SUM(CASE WHEN prospector_calls.answered_at IS NOT NULL THEN 1 ELSE 0 END)',
            'booked_count' => "SUM(CASE WHEN prospector_calls.disposition IN ('booked','interested','appointment_set') THEN 1 ELSE 0 END)",
            'total_talk_time' => 'ROUND(SUM(COALESCE(prospector_calls.talk_seconds, 0)) / 60, 1)',
            'avg_talk_time' => 'ROUND(AVG(CASE WHEN prospector_calls.talk_seconds > 0 THEN prospector_calls.talk_seconds END), 0)',
            'avg_duration' => 'ROUND(AVG(CASE WHEN prospector_calls.duration_seconds > 0 THEN prospector_calls.duration_seconds END), 0)',
            'unique_contacts' => 'COUNT(DISTINCT prospector_calls.contact_id)',
            'voicemail_count' => "SUM(CASE WHEN prospector_calls.disposition = 'voicemail' THEN 1 ELSE 0 END)",
            'dnc_count' => "SUM(CASE WHEN prospector_calls.disposition = 'dnc' THEN 1 ELSE 0 END)",
            default => 'COUNT(*)',
        };
    }

    /**
     * Format a dimension value for display labels.
     */
    protected function formatDimensionLabel(string $dimension, mixed $value): string
    {
        if ($value === null || $value === '' || $value === 'none' || $value === 'unknown') {
            return 'Unknown';
        }

        return match ($dimension) {
            'hour' => ((int) $value % 12 ?: 12).($value < 12 ? ' AM' : ' PM'),
            'disposition' => ucfirst(str_replace('_', ' ', $value)),
            'amd_result' => ucfirst($value),
            'direction' => ucfirst($value),
            default => (string) $value,
        };
    }
}
