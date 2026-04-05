# Reports, BI Chart Builder & Contact Archive — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the unused Lists page with a Reports page featuring a BI-style chart builder, saved chart dashboard, and contact archive with re-engagement.

**Architecture:** A `ReportMetricService` computes any metric/dimension combo from the calls table. The Reports page has 3 Livewire tabs: Dashboard (saved charts grid), Chart Builder (dropdowns → live Chart.js preview), Archive (filterable archived contacts with bulk re-engagement). Saved chart configs are stored as JSON in `prospector_saved_charts`.

**Tech Stack:** Laravel/Livewire 4, Chart.js (CDN), Alpine.js, MySQL aggregate queries

**Spec:** `docs/superpowers/specs/2026-04-05-reports-archive-design.md`

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `database/migrations/xxx_create_prospector_saved_charts.php` | Create | Saved chart templates table |
| `database/migrations/xxx_add_archived_at_to_campaign_contact.php` | Create | Archive timestamp column |
| `app/Models/Prospector/SavedChart.php` | Create | Saved chart model |
| `app/Services/Prospector/ReportMetricService.php` | Create | Core query engine — metrics, dimensions, filters |
| `resources/views/pages/prospector/⚡reports.blade.php` | Create | Reports page with 3 tabs (replaces contacts in sidebar) |
| `app/Services/Prospector/DialerOrchestrationService.php` | Modify | Add 'archive' disposition route action |
| `routes/prospector.php` | Modify | Add reports route |
| `resources/views/layouts/app/sidebar.blade.php` | Modify | Replace Contacts link with Reports |
| `config/prospector.php` | Modify | Document archive action in dispositions |

---

### Task 1: Database — Migrations and Models

**Files:**
- Create: `database/migrations/2026_04_05_xxx_create_prospector_saved_charts_table.php`
- Create: `database/migrations/2026_04_05_xxx_add_archived_at_to_prospector_campaign_contact.php`
- Create: `app/Models/Prospector/SavedChart.php`

- [ ] **Step 1: Create saved_charts migration**

```bash
php artisan make:migration create_prospector_saved_charts_table --no-interaction
```

Edit the migration:

```php
public function up(): void
{
    Schema::create('prospector_saved_charts', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->string('name');
        $table->json('config');
        $table->integer('sort_order')->default(0);
        $table->boolean('is_pinned')->default(true);
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('prospector_saved_charts');
}
```

- [ ] **Step 2: Create archived_at migration**

```bash
php artisan make:migration add_archived_at_to_prospector_campaign_contact --no-interaction
```

Edit the migration:

```php
public function up(): void
{
    Schema::table('prospector_campaign_contact', function (Blueprint $table) {
        $table->timestamp('archived_at')->nullable()->after('exhausted_at');
    });
}

public function down(): void
{
    Schema::table('prospector_campaign_contact', function (Blueprint $table) {
        $table->dropColumn('archived_at');
    });
}
```

- [ ] **Step 3: Create SavedChart model**

```php
<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Model;

class SavedChart extends Model
{
    protected $table = 'prospector_saved_charts';

    protected $fillable = [
        'user_id',
        'name',
        'config',
        'sort_order',
        'is_pinned',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_pinned' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
}
```

- [ ] **Step 4: Run migrations**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/ app/Models/Prospector/SavedChart.php
git commit -m "feat(prospector): add saved_charts table and archived_at column for reports"
```

---

### Task 2: ReportMetricService — Core Query Engine

**Files:**
- Create: `app/Services/Prospector/ReportMetricService.php`

- [ ] **Step 1: Create the service**

```php
<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Call;
use Illuminate\Support\Facades\DB;

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

        if (! empty($filters['company_ids'])) {
            $query->whereIn('prospector_calls.company_id', (array) $filters['company_ids']);
        }
        if (! empty($filters['campaign_ids'])) {
            $query->whereIn('prospector_calls.campaign_id', (array) $filters['campaign_ids']);
        }
        if (! empty($filters['agent_ids'])) {
            $query->whereIn('prospector_calls.agent_id', (array) $filters['agent_ids']);
        }
        if (! empty($filters['dispositions'])) {
            $query->whereIn('prospector_calls.disposition', (array) $filters['dispositions']);
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
```

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint app/Services/Prospector/ReportMetricService.php --format agent
```

- [ ] **Step 3: Commit**

```bash
git add app/Services/Prospector/ReportMetricService.php
git commit -m "feat(prospector): add ReportMetricService — BI query engine for charts"
```

---

### Task 3: Archive Disposition Route

**Files:**
- Modify: `app/Services/Prospector/DialerOrchestrationService.php`

- [ ] **Step 1: Add archive action to disposition routing**

Find the `switch ($route['action'])` block (around line 412) and add the `archive` case before `default`:

```php
                case 'archive':
                    $entry->update([
                        'status' => 'archived',
                        'archived_at' => now(),
                    ]);
                    break;
```

- [ ] **Step 2: Commit**

```bash
git add app/Services/Prospector/DialerOrchestrationService.php
git commit -m "feat(prospector): add 'archive' disposition route action"
```

---

### Task 4: Reports Page — Livewire Component (PHP class)

**Files:**
- Create: `resources/views/pages/prospector/⚡reports.blade.php`

This is a large file. Task 4 creates the PHP class. Task 5 creates the Blade template. Task 6 adds the Chart Builder tab. Task 7 adds the Archive tab.

- [ ] **Step 1: Create the Livewire SFC with PHP class only**

Create `resources/views/pages/prospector/⚡reports.blade.php` with the full PHP class at the top. The template will be added in subsequent tasks.

```php
<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\SavedChart;
use App\Services\Prospector\ReportMetricService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Reports')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'dashboard'; // dashboard, builder, archive

    // ── Chart Builder State ──
    public string $chartMetric = 'call_count';
    public string $chartDimension = 'date';
    public ?string $chartGroupBy = null;
    public string $chartType = 'bar';
    public string $dateRange = 'last_30_days';
    public ?string $dateFrom = null;
    public ?string $dateTo = null;
    public array $filterCompanyIds = [];
    public array $filterCampaignIds = [];
    public array $filterAgentIds = [];
    public array $filterDispositions = [];
    public string $filterDirection = '';

    // ── Save Chart Modal ──
    public bool $showSaveModal = false;
    public string $saveChartName = '';
    public ?int $editingChartId = null;

    // ── Archive Filters ──
    public string $archiveSearch = '';
    public ?int $archiveCompanyId = null;
    public ?string $archiveDisposition = null;
    public ?string $archiveDateFrom = null;
    public ?string $archiveDateTo = null;

    // ── Re-engage Modal ──
    public bool $showReengageModal = false;
    public array $selectedArchiveIds = [];
    public ?int $reengageCampaignId = null;

    public function boot(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    // ── Chart Builder ──

    public function getChartData(): array
    {
        $service = app(ReportMetricService::class);

        return $service->query([
            'metric' => $this->chartMetric,
            'dimension' => $this->chartDimension,
            'group_by' => $this->chartGroupBy ?: null,
            'filters' => [
                'date_range' => $this->dateRange,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'company_ids' => $this->filterCompanyIds,
                'campaign_ids' => $this->filterCampaignIds,
                'agent_ids' => $this->filterAgentIds,
                'dispositions' => $this->filterDispositions,
                'direction' => $this->filterDirection ?: null,
            ],
        ]);
    }

    public function getSavedChartData(int $chartId): array
    {
        $chart = SavedChart::find($chartId);

        if (! $chart) {
            return ['labels' => [], 'datasets' => [], 'summary' => []];
        }

        $service = app(ReportMetricService::class);

        return $service->query($chart->config);
    }

    // ── Saved Charts ──

    #[Computed]
    public function savedCharts(): \Illuminate\Support\Collection
    {
        return SavedChart::forUser(Auth::id())
            ->pinned()
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function allSavedCharts(): \Illuminate\Support\Collection
    {
        return SavedChart::forUser(Auth::id())
            ->orderBy('sort_order')
            ->get();
    }

    public function saveChart(): void
    {
        $this->validate(['saveChartName' => 'required|string|max:100']);

        $config = [
            'metric' => $this->chartMetric,
            'dimension' => $this->chartDimension,
            'group_by' => $this->chartGroupBy ?: null,
            'chart_type' => $this->chartType,
            'filters' => [
                'date_range' => $this->dateRange,
                'date_from' => $this->dateFrom,
                'date_to' => $this->dateTo,
                'company_ids' => $this->filterCompanyIds,
                'campaign_ids' => $this->filterCampaignIds,
                'agent_ids' => $this->filterAgentIds,
                'dispositions' => $this->filterDispositions,
                'direction' => $this->filterDirection ?: null,
            ],
        ];

        if ($this->editingChartId) {
            SavedChart::where('id', $this->editingChartId)
                ->where('user_id', Auth::id())
                ->update(['name' => $this->saveChartName, 'config' => $config]);
        } else {
            SavedChart::create([
                'user_id' => Auth::id(),
                'name' => $this->saveChartName,
                'config' => $config,
                'sort_order' => SavedChart::forUser(Auth::id())->max('sort_order') + 1,
            ]);
        }

        $this->showSaveModal = false;
        $this->saveChartName = '';
        $this->editingChartId = null;
        unset($this->savedCharts, $this->allSavedCharts);
    }

    public function loadChart(int $chartId): void
    {
        $chart = SavedChart::where('id', $chartId)->where('user_id', Auth::id())->first();

        if (! $chart) {
            return;
        }

        $config = $chart->config;
        $this->chartMetric = $config['metric'] ?? 'call_count';
        $this->chartDimension = $config['dimension'] ?? 'date';
        $this->chartGroupBy = $config['group_by'] ?? null;
        $this->chartType = $config['chart_type'] ?? 'bar';
        $this->dateRange = $config['filters']['date_range'] ?? 'last_30_days';
        $this->dateFrom = $config['filters']['date_from'] ?? null;
        $this->dateTo = $config['filters']['date_to'] ?? null;
        $this->filterCompanyIds = $config['filters']['company_ids'] ?? [];
        $this->filterCampaignIds = $config['filters']['campaign_ids'] ?? [];
        $this->filterAgentIds = $config['filters']['agent_ids'] ?? [];
        $this->filterDispositions = $config['filters']['dispositions'] ?? [];
        $this->filterDirection = $config['filters']['direction'] ?? '';

        $this->editingChartId = $chart->id;
        $this->saveChartName = $chart->name;
        $this->tab = 'builder';
    }

    public function deleteChart(int $chartId): void
    {
        SavedChart::where('id', $chartId)->where('user_id', Auth::id())->delete();
        unset($this->savedCharts, $this->allSavedCharts);
    }

    public function togglePin(int $chartId): void
    {
        $chart = SavedChart::where('id', $chartId)->where('user_id', Auth::id())->first();

        if ($chart) {
            $chart->update(['is_pinned' => ! $chart->is_pinned]);
            unset($this->savedCharts, $this->allSavedCharts);
        }
    }

    // ── Dropdowns ──

    #[Computed]
    public function companies(): \Illuminate\Support\Collection
    {
        return Company::where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function campaigns(): \Illuminate\Support\Collection
    {
        return Campaign::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function agents(): \Illuminate\Support\Collection
    {
        return Agent::with('user')->get()->groupBy('user_id')->map(fn ($g) => $g->first())->values();
    }

    #[Computed]
    public function dispositionOptions(): array
    {
        return collect(config('prospector.dispositions', []))
            ->map(fn ($v) => is_array($v) ? $v['label'] : $v)
            ->all();
    }

    // ── Archive ──

    #[Computed]
    public function archivedContacts(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return CampaignContact::where('status', 'archived')
            ->with(['contact.company', 'campaign'])
            ->when($this->archiveSearch, function ($q) {
                $q->whereHas('contact', fn ($cq) => $cq->where('first_name', 'like', "%{$this->archiveSearch}%")
                    ->orWhere('last_name', 'like', "%{$this->archiveSearch}%")
                    ->orWhere('phone', 'like', "%{$this->archiveSearch}%"));
            })
            ->when($this->archiveCompanyId, fn ($q) => $q->whereHas('contact', fn ($cq) => $cq->where('company_id', $this->archiveCompanyId)))
            ->when($this->archiveDisposition, fn ($q) => $q->where('last_disposition', $this->archiveDisposition))
            ->when($this->archiveDateFrom, fn ($q) => $q->where('archived_at', '>=', $this->archiveDateFrom))
            ->when($this->archiveDateTo, fn ($q) => $q->where('archived_at', '<=', $this->archiveDateTo.' 23:59:59'))
            ->orderByDesc('archived_at')
            ->paginate(50);
    }

    public function reengageContacts(): void
    {
        if (empty($this->selectedArchiveIds) || ! $this->reengageCampaignId) {
            return;
        }

        $campaign = Campaign::find($this->reengageCampaignId);

        if (! $campaign) {
            return;
        }

        $maxSort = CampaignContact::where('campaign_id', $campaign->id)->max('sort_order') ?? -1;
        $enrolled = 0;

        foreach ($this->selectedArchiveIds as $entryId) {
            $entry = CampaignContact::find($entryId);

            if (! $entry || ! $entry->contact_id) {
                continue;
            }

            // If same campaign, just re-queue
            if ($entry->campaign_id === $campaign->id) {
                $entry->update([
                    'status' => 'queued',
                    'sort_order' => 0,
                    'is_priority' => true,
                    'priority_reason' => 're-engaged',
                    'archived_at' => null,
                    'calls_today' => 0,
                ]);
            } else {
                // Different campaign — create new entry
                CampaignContact::firstOrCreate(
                    ['campaign_id' => $campaign->id, 'contact_id' => $entry->contact_id],
                    [
                        'status' => 'queued',
                        'sort_order' => ++$maxSort,
                        'is_priority' => true,
                        'priority_reason' => 're-engaged',
                        'calls_today' => 0,
                        'total_attempts' => 0,
                        'attempts' => 0,
                        'entered_at' => now(),
                    ],
                );
            }

            $enrolled++;
        }

        $this->showReengageModal = false;
        $this->selectedArchiveIds = [];
        $this->reengageCampaignId = null;
        unset($this->archivedContacts);

        session()->flash('success', "{$enrolled} contact(s) re-engaged.");
    }
}; ?>

<div>
    {{-- Template added in Tasks 5-7 --}}
    <p>Reports page — template pending</p>
</div>
```

- [ ] **Step 2: Commit**

```bash
git add "resources/views/pages/prospector/⚡reports.blade.php"
git commit -m "feat(prospector): add Reports page Livewire component (PHP class)"
```

---

### Task 5: Reports Page — Dashboard Tab Template

**Files:**
- Modify: `resources/views/pages/prospector/⚡reports.blade.php` (replace template section)

- [ ] **Step 1: Replace the placeholder template**

Replace `<div>` template at the bottom of the file with the full 3-tab layout. Start with Dashboard tab:

The template should include:
- Tab navigation (Dashboard | Chart Builder | Archive)
- Dashboard tab: grid of saved charts, each rendered as a Chart.js canvas via Alpine
- Chart.js loaded from CDN in a `@push('scripts')` or inline `<script>`
- Each saved chart card: name, chart canvas, edit/delete/unpin buttons
- Empty state when no saved charts

This is a large template — the subagent implementing this should create the full Blade template with Alpine x-data for Chart.js rendering. Key Alpine structure:

```javascript
x-data="{
    tab: $wire.entangle('tab'),
    charts: {},  // chartId -> Chart instance

    async renderSavedChart(chartId, canvasEl) {
        const data = await $wire.getSavedChartData(chartId);
        const config = await $wire.get('savedCharts').find(c => c.id === chartId);
        if (this.charts[chartId]) this.charts[chartId].destroy();
        this.charts[chartId] = new Chart(canvasEl, {
            type: config?.config?.chart_type || 'bar',
            data: { labels: data.labels, datasets: data.datasets.map((ds, i) => ({
                ...ds, backgroundColor: this.colors[i % this.colors.length],
                borderColor: this.colors[i % this.colors.length],
            }))},
            options: { responsive: true, maintainAspectRatio: false }
        });
    },

    colors: ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16'],
}"
```

- [ ] **Step 2: Commit**

```bash
git add "resources/views/pages/prospector/⚡reports.blade.php"
git commit -m "feat(prospector): add Reports Dashboard tab with saved chart grid"
```

---

### Task 6: Reports Page — Chart Builder Tab Template

**Files:**
- Modify: `resources/views/pages/prospector/⚡reports.blade.php`

- [ ] **Step 1: Add Chart Builder tab content**

Inside the tab conditional, add the builder UI:
- Left panel: metric dropdown, dimension dropdown, group-by dropdown, chart type selector (icons), filters (date range, company, campaign, agent, disposition, direction)
- Right panel: Chart.js canvas with live preview
- Bottom: summary stats row (total, avg, max, min) + Save button
- Save modal: name input + save/cancel

Alpine method for live preview:

```javascript
async renderBuilderChart() {
    const data = await $wire.getChartData();
    if (this.builderChart) this.builderChart.destroy();
    this.builderChart = new Chart(this.$refs.builderCanvas, {
        type: $wire.chartType,
        data: { labels: data.labels, datasets: data.datasets.map((ds, i) => ({
            ...ds, backgroundColor: this.colors[i % this.colors.length] + '99',
            borderColor: this.colors[i % this.colors.length], borderWidth: 2, fill: $wire.chartType === 'area',
        }))},
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: data.datasets.length > 1 } } }
    });
    this.builderSummary = data.summary;
}
```

Watch for changes to re-render:
```javascript
$watch('$wire.chartMetric', () => this.renderBuilderChart());
$watch('$wire.chartDimension', () => this.renderBuilderChart());
$watch('$wire.chartGroupBy', () => this.renderBuilderChart());
$watch('$wire.chartType', () => this.renderBuilderChart());
$watch('$wire.dateRange', () => this.renderBuilderChart());
```

- [ ] **Step 2: Commit**

```bash
git add "resources/views/pages/prospector/⚡reports.blade.php"
git commit -m "feat(prospector): add Chart Builder tab with BI controls and live preview"
```

---

### Task 7: Reports Page — Archive Tab Template

**Files:**
- Modify: `resources/views/pages/prospector/⚡reports.blade.php`

- [ ] **Step 1: Add Archive tab content**

- Filter bar: search, company dropdown, disposition dropdown, date range
- Table: checkbox, name, phone, company, last disposition, archived date, total calls, actions
- Bulk action bar (when selections): "Re-engage X contacts" button
- Re-engage modal: campaign picker, submit button
- Pagination

- [ ] **Step 2: Commit**

```bash
git add "resources/views/pages/prospector/⚡reports.blade.php"
git commit -m "feat(prospector): add Archive tab with search, filters, and re-engagement"
```

---

### Task 8: Wire Routes + Sidebar

**Files:**
- Modify: `routes/prospector.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php`

- [ ] **Step 1: Add reports route**

In `routes/prospector.php`, add after the contacts route:

```php
// Reports & Analytics
Route::livewire('/reports', 'pages::prospector.reports')->name('reports');
```

- [ ] **Step 2: Update sidebar**

In `resources/views/layouts/app/sidebar.blade.php`, find the Contacts sidebar item and rename it to Reports with a chart icon:

Replace:
```blade
<flux:sidebar.item icon="users" :href="route('prospector.contacts')" :current="request()->routeIs('prospector.contacts')" wire:navigate>
    {{ __('Contacts') }}
</flux:sidebar.item>
```

With:
```blade
<flux:sidebar.item icon="chart-bar-square" :href="route('prospector.reports')" :current="request()->routeIs('prospector.reports')" wire:navigate>
    {{ __('Reports') }}
</flux:sidebar.item>
<flux:sidebar.item icon="users" :href="route('prospector.contacts')" :current="request()->routeIs('prospector.contacts')" wire:navigate>
    {{ __('Contacts') }}
</flux:sidebar.item>
```

Keep Contacts in the sidebar too — Reports is a new addition, not a replacement.

- [ ] **Step 3: Commit**

```bash
git add routes/prospector.php resources/views/layouts/app/sidebar.blade.php
git commit -m "feat(prospector): add Reports route and sidebar link"
```

---

### Task 9: Tests + Final Verification

**Files:**
- Test: `tests/Feature/Prospector/ReportMetricServiceTest.php`

- [ ] **Step 1: Create test file**

```bash
php artisan make:test Prospector/ReportMetricServiceTest --pest --no-interaction
```

Write tests for the ReportMetricService:

```php
<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\ReportMetricService;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->agent = Agent::factory()->forCompany($this->company)->create();
    $this->campaign = Campaign::factory()->forCompany($this->company)->create();

    $this->contact = Contact::factory()->forCompany($this->company)->create();
});

it('counts calls by date', function () {
    Call::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'campaign_id' => $this->campaign->id,
        'started_at' => today(),
        'direction' => 'outbound',
    ]);

    $service = new ReportMetricService;
    $result = $service->query([
        'metric' => 'call_count',
        'dimension' => 'date',
        'filters' => ['date_range' => 'today'],
    ]);

    expect($result['labels'])->toHaveCount(1);
    expect($result['datasets'][0]['data'][0])->toBe(3.0);
});

it('calculates booking rate', function () {
    Call::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'started_at' => today(),
        'answered_at' => now(),
        'disposition' => 'booked',
        'direction' => 'outbound',
    ]);
    Call::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'started_at' => today(),
        'answered_at' => now(),
        'disposition' => 'not_interested',
        'direction' => 'outbound',
    ]);

    $service = new ReportMetricService;
    $result = $service->query([
        'metric' => 'booking_rate',
        'dimension' => 'date',
        'filters' => ['date_range' => 'today'],
    ]);

    // 2 booked out of 5 connected = 40%
    expect($result['datasets'][0]['data'][0])->toBe(40.0);
});

it('groups by agent', function () {
    $agent2 = Agent::factory()->forCompany($this->company)->create();

    Call::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'started_at' => today(),
        'direction' => 'outbound',
    ]);
    Call::factory()->count(2)->create([
        'company_id' => $this->company->id,
        'agent_id' => $agent2->id,
        'contact_id' => $this->contact->id,
        'started_at' => today(),
        'direction' => 'outbound',
    ]);

    $service = new ReportMetricService;
    $result = $service->query([
        'metric' => 'call_count',
        'dimension' => 'agent',
        'filters' => ['date_range' => 'today'],
    ]);

    expect($result['datasets'][0]['data'])->toContain(3.0);
    expect($result['datasets'][0]['data'])->toContain(2.0);
});

it('returns available metrics and dimensions', function () {
    expect(ReportMetricService::availableMetrics())->toHaveKey('call_count');
    expect(ReportMetricService::availableMetrics())->toHaveKey('booking_rate');
    expect(ReportMetricService::availableDimensions())->toHaveKey('agent');
    expect(ReportMetricService::availableDimensions())->toHaveKey('disposition');
});

it('filters by company', function () {
    $otherCompany = Company::factory()->forAccount($this->account)->create();

    Call::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'started_at' => today(),
        'direction' => 'outbound',
    ]);
    Call::factory()->create([
        'company_id' => $otherCompany->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'started_at' => today(),
        'direction' => 'outbound',
    ]);

    $service = new ReportMetricService;
    $result = $service->query([
        'metric' => 'call_count',
        'dimension' => 'date',
        'filters' => ['date_range' => 'today', 'company_ids' => [$this->company->id]],
    ]);

    expect($result['datasets'][0]['data'][0])->toBe(3.0);
});
```

- [ ] **Step 2: Run tests**

```bash
php artisan test --compact --filter="ReportMetricService"
```

- [ ] **Step 3: Run full Prospector suite**

```bash
php artisan test --compact --filter="Prospector"
```

- [ ] **Step 4: Run Pint + build**

```bash
vendor/bin/pint --dirty --format agent
npm run build
```

- [ ] **Step 5: Final commit and push**

```bash
git push origin main
```

---

## Verification Checklist

1. **Chart Builder**: `/prospector/reports?tab=builder` → select metric, dimension → chart renders → change chart type → re-renders
2. **Save Chart**: Save with name → appears on Dashboard tab with live data
3. **Dashboard**: Saved charts render in a grid with correct chart types
4. **Load/Edit**: Click saved chart → loads into builder with all settings → save updates it
5. **Archive**: Disposition a contact with archive route → appears in Archive tab → filter by company/disposition
6. **Re-engage**: Select archived contacts → Re-engage → pick campaign → contacts appear in queue
7. **Filters**: Date range, company, agent filters all narrow the data correctly
8. **Grouped charts**: Pick dimension + group_by → multiple datasets render as stacked/grouped bars or multi-line
