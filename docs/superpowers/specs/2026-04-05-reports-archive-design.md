# Reports, BI Chart Builder, and Contact Archive — Design Spec

## Date: 2026-04-05
## Status: Approved

---

## Overview

Replace the unused Lists page with a full Reports page featuring three tabs: a saved chart dashboard, a BI-style chart builder, and a contact archive with re-engagement. The system provides a flexible data engine that can compute any metric across any dimension from the calls/contacts/campaigns data, render it as configurable Chart.js visualizations, and save chart configurations as reusable templates.

---

## Page Structure

**Route:** `/prospector/reports` (replaces `/prospector/contacts` in sidebar as "Reports")
**Component:** `resources/views/pages/prospector/⚡reports.blade.php` (Livewire SFC)

### Tab 1: Dashboard

Grid of saved/pinned chart templates. Each chart renders live data on load. Users can:
- Rearrange chart order (drag or sort arrows)
- Remove charts from dashboard
- Click a chart to open it in the Chart Builder for editing
- Empty state prompts "Build your first chart"

### Tab 2: Chart Builder

Full BI-style chart configuration:

**Controls (left sidebar or top bar):**
- **Metric**: dropdown — what to measure
- **Dimension**: dropdown — x-axis / primary grouping
- **Group By**: dropdown (optional) — secondary breakdown for comparative charts
- **Chart Type**: icon selector — Bar, Line, Area, Pie, Donut, Stacked Bar, Horizontal Bar
- **Filters**: collapsible panel
  - Date range (preset: today, this week, this month, last 30 days, custom)
  - Company (multi-select)
  - Campaign (multi-select)
  - Agent (multi-select)
  - Disposition (multi-select)
  - Direction (inbound/outbound/all)
- **Save**: name field + save button → stores to `prospector_saved_charts`

**Chart area (main):**
- Live preview updates as controls change
- Chart.js canvas rendering
- Data table toggle below chart (show raw numbers)

### Tab 3: Archive

Searchable, filterable table of archived contacts:

**Filters:**
- Company
- Disposition (at time of archive)
- Date archived (range)
- Contact source
- Tags
- Pipeline stage
- State/city
- Search (name, phone, email)

**Table columns:**
- Name, Phone, Company, Last Disposition, Archived Date, Total Calls, Last Agent

**Actions:**
- Bulk select → "Re-engage" → pick campaign → contacts enrolled in queue
- Single contact: view call history, re-engage, edit
- Export filtered list as CSV

---

## Data Engine: ReportMetricService

**File:** `app/Services/Prospector/ReportMetricService.php`

Core service that accepts a chart configuration and returns aggregated query results formatted for Chart.js.

### Input: Chart Config

```php
[
    'metric' => 'count',              // What to measure
    'metric_field' => 'id',           // Which DB field (for sum/avg)
    'dimension' => 'date',            // X-axis grouping
    'group_by' => 'agent',            // Optional secondary grouping
    'chart_type' => 'bar',            // For formatting hints
    'filters' => [
        'date_from' => '2026-03-01',
        'date_to' => '2026-04-05',
        'company_ids' => [1],
        'campaign_ids' => [1, 2],
        'agent_ids' => [],
        'dispositions' => [],
        'direction' => 'outbound',
    ],
]
```

### Output: Chart Data

```php
[
    'labels' => ['Mar 1', 'Mar 2', 'Mar 3', ...],
    'datasets' => [
        ['label' => 'Agent A', 'data' => [5, 3, 8, ...]],
        ['label' => 'Agent B', 'data' => [2, 7, 4, ...]],
    ],
    'summary' => [
        'total' => 156,
        'average' => 5.2,
        'max' => 12,
        'min' => 0,
    ],
]
```

### Available Metrics

| Key | Field | Aggregation | Description |
|-----|-------|-------------|-------------|
| `call_count` | `calls.id` | COUNT | Total calls |
| `connected_count` | `calls.answered_at` | COUNT NOT NULL | Connected calls |
| `booked_count` | `calls.disposition` | COUNT IN (booked, interested, appointment_set) | Bookings |
| `booking_rate` | computed | booked / connected * 100 | Booking conversion rate |
| `connect_rate` | computed | connected / total * 100 | Connection rate |
| `total_talk_time` | `calls.talk_seconds` | SUM | Total talk time (seconds) |
| `avg_talk_time` | `calls.talk_seconds` | AVG | Average talk time |
| `avg_duration` | `calls.duration_seconds` | AVG | Average call duration |
| `total_duration` | `calls.duration_seconds` | SUM | Total call duration |
| `unique_contacts` | `calls.contact_id` | COUNT DISTINCT | Unique contacts reached |
| `voicemail_count` | `calls.disposition` | COUNT = voicemail | Voicemails left |
| `dnc_count` | `calls.disposition` | COUNT = dnc | DNC dispositions |

### Available Dimensions

| Key | Source Table | SQL Expression | Labels |
|-----|-------------|----------------|--------|
| `date` | calls | `DATE(started_at)` | 'Mar 1', 'Mar 2' |
| `hour` | calls | `HOUR(started_at)` | '8 AM', '9 AM' |
| `day_of_week` | calls | `DAYNAME(started_at)` | 'Monday', 'Tuesday' |
| `week` | calls | `YEARWEEK(started_at)` | 'Week 13', 'Week 14' |
| `month` | calls | `DATE_FORMAT(started_at, '%Y-%m')` | 'Mar 2026', 'Apr 2026' |
| `agent` | calls → agents → users | `agent_id` | Agent name |
| `campaign` | calls | `campaign_id` | Campaign name |
| `company` | calls | `company_id` | Company name |
| `disposition` | calls | `disposition` | 'Booked', 'No Answer' |
| `amd_result` | calls | `amd_result` | 'Human', 'Machine' |
| `direction` | calls | `direction` | 'Inbound', 'Outbound' |
| `contact_source` | contacts | `source` | 'Webhook', 'Manual' |
| `contact_timezone` | contacts | `timezone` | 'America/Denver' |
| `contact_state` | contacts | `state` | 'CO', 'NY' |
| `pipeline_stage` | contacts → pipeline_stages | `pipeline_stage_id` | Stage name |
| `duration_bucket` | calls | CASE expression | '0-30s', '30-60s', '1-5min', '5+min' |
| `attempt_bucket` | campaign_contact | CASE expression | '1', '2-3', '4-5', '6+' |
| `days_in_campaign` | campaign_contact | DATEDIFF(now, entered_at) | '0-7', '8-14', '15-30', '30+' |

### Query Building

The service builds a query dynamically:

1. Start with `Call::query()` as the base
2. Join `contacts` table when contact dimensions are used
3. Join `campaign_contact` table when campaign contact dimensions are used
4. Apply filters (WHERE clauses)
5. Apply dimension as GROUP BY
6. Apply group_by as secondary GROUP BY (for datasets)
7. Apply metric as SELECT aggregate
8. Format results for Chart.js

For computed rates (booking_rate, connect_rate), the service runs two sub-queries (numerator and denominator) and computes the ratio per group.

---

## Saved Charts

### Database: `prospector_saved_charts`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK | Creator |
| name | string | Display name |
| config | json | Full chart config (metric, dimension, group_by, chart_type, filters) |
| sort_order | int | Position on dashboard |
| is_pinned | bool | Show on dashboard tab |
| created_at, updated_at | |

### Config JSON Structure

```json
{
    "metric": "booking_rate",
    "dimension": "date",
    "group_by": "agent",
    "chart_type": "line",
    "filters": {
        "date_range": "last_30_days",
        "date_from": null,
        "date_to": null,
        "company_ids": [],
        "campaign_ids": [],
        "agent_ids": [],
        "dispositions": [],
        "direction": "outbound"
    }
}
```

Date range presets (`date_range` field): `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `last_30_days`, `last_90_days`, `custom`. When preset is used, `date_from`/`date_to` are computed at render time (always fresh relative dates).

---

## Archive System

### Contact Archive Status

Add `archived` as a new `CampaignContact` status value. When a contact is archived:
- `CampaignContact.status = 'archived'`
- `CampaignContact.archived_at = now()` (new column)
- Contact itself is NOT modified — all relationships, call history, notes preserved

### Disposition Route: Archive

Add `archive` as an action in campaign `disposition_routes`:

```php
// In DialerOrchestrationService::dispositionCall()
case 'archive':
    $entry->update([
        'status' => 'archived',
        'archived_at' => now(),
    ]);
    break;
```

Campaign settings UI already supports disposition route configuration. The new `archive` option appears alongside `stay`, `next_campaign`, `remove`.

### Archive Tab Query

```php
CampaignContact::where('status', 'archived')
    ->with(['contact.company', 'campaign'])
    ->when($filters['company_id'], fn($q, $v) => $q->whereHas('contact', fn($cq) => $cq->where('company_id', $v)))
    ->when($filters['disposition'], fn($q, $v) => $q->where('last_disposition', $v))
    ->when($filters['date_from'], fn($q, $v) => $q->where('archived_at', '>=', $v))
    ->when($filters['date_to'], fn($q, $v) => $q->where('archived_at', '<=', $v))
    ->when($filters['search'], fn($q, $v) => $q->whereHas('contact', fn($cq) => $cq->search($v)))
    ->paginate(50);
```

### Re-engagement Flow

1. User filters archive to a segment
2. Selects contacts (bulk or individual)
3. Clicks "Re-engage" → modal with campaign picker
4. Server:
   - Updates `CampaignContact.status = 'queued'` for same campaign, OR
   - Creates new `CampaignContact` entry for different campaign
   - Sets `sort_order = 0` (front of queue), `is_priority = true`, `priority_reason = 're-engaged'`
5. Queue refreshes with re-engaged contacts at the top

---

## Frontend: Chart.js Integration

### JavaScript

Chart.js loaded via CDN or npm. Alpine.js component wraps Chart.js:

```javascript
// In the reports blade, Alpine x-data manages chart state
{
    chart: null,
    chartConfig: { metric: 'call_count', dimension: 'date', ... },

    async renderChart() {
        const data = await $wire.getChartData(this.chartConfig);
        if (this.chart) this.chart.destroy();
        this.chart = new Chart(this.$refs.canvas, {
            type: this.chartConfig.chart_type,
            data: { labels: data.labels, datasets: data.datasets },
            options: { responsive: true, ... }
        });
    }
}
```

### Chart Types Supported

| Type | Best For |
|------|----------|
| Bar | Comparing values across categories (agents, campaigns) |
| Stacked Bar | Composition breakdown (dispositions per agent) |
| Horizontal Bar | Many categories with long labels |
| Line | Trends over time (daily calls, weekly booking rate) |
| Area | Volume trends over time (filled) |
| Pie | Proportion of whole (disposition breakdown) |
| Donut | Same as pie, different style |

---

## Database Changes

### New table: `prospector_saved_charts`

```
id, user_id (FK), name, config (json), sort_order (int), is_pinned (bool), timestamps
```

### Modified table: `prospector_campaign_contact`

Add column: `archived_at` (timestamp, nullable)

### Modified config: `config/prospector.php`

Add `archive` to disposition route actions list.

---

## Files to Create/Modify

| File | Action | Purpose |
|------|--------|---------|
| `app/Services/Prospector/ReportMetricService.php` | Create | Query engine for chart data |
| `app/Models/Prospector/SavedChart.php` | Create | Saved chart template model |
| `resources/views/pages/prospector/⚡reports.blade.php` | Create | Reports page (3 tabs) |
| `database/migrations/xxx_create_prospector_saved_charts.php` | Create | Saved charts table |
| `database/migrations/xxx_add_archived_at_to_campaign_contact.php` | Create | Archive timestamp column |
| `app/Services/Prospector/DialerOrchestrationService.php` | Modify | Add 'archive' disposition route action |
| `routes/prospector.php` | Modify | Replace contacts route with reports route |
| `resources/views/layouts/app/sidebar.blade.php` | Modify | Rename sidebar link |
| `config/prospector.php` | Modify | Add archive action to disposition routes |

---

## Verification

1. **Chart Builder**: Open Reports → Chart Builder tab → select metric "call_count", dimension "date" → see line chart of daily calls. Change to "agent" dimension → see bar chart per agent. Add group_by "disposition" → see stacked bars.
2. **Save Chart**: Click Save → name it "Daily Calls by Agent" → appears on Dashboard tab.
3. **Dashboard**: Open Dashboard tab → see saved chart with live data. Remove it. Re-add from Chart Builder.
4. **Archive**: Disposition a call as "booked" with archive route → contact appears in Archive tab. Filter by company, date. Select it → Re-engage → pick campaign → contact appears in campaign queue.
5. **Re-engagement**: Bulk select archived contacts → Re-engage → verify they're in the campaign queue with priority.
6. **Data accuracy**: Compare chart numbers with raw SQL queries on the calls table.
