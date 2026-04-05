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
            ->when($this->archiveDateTo, fn ($q) => $q->where('archived_at', '<=', $this->archiveDateTo . ' 23:59:59'))
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

<div
    x-data="{
        charts: {},
        builderChart: null,
        builderSummary: null,
        colors: ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#ec4899','#06b6d4','#84cc16'],

        resolveChartType(type) {
            if (type === 'area') return 'line';
            if (type === 'horizontal-bar') return 'bar';
            if (type === 'donut') return 'doughnut';
            if (type === 'stacked-bar') return 'bar';
            return type || 'bar';
        },

        async renderBuilderChart() {
            const data = await $wire.getChartData();
            if (this.builderChart) this.builderChart.destroy();
            const canvas = this.$refs.builderCanvas;
            if (!canvas) return;
            const type = this.resolveChartType($wire.chartType);
            this.builderChart = new Chart(canvas, {
                type: type,
                data: {
                    labels: data.labels,
                    datasets: data.datasets.map((ds, i) => ({
                        ...ds,
                        backgroundColor: type === 'line' ? 'transparent' : (this.colors[i % this.colors.length] + '99'),
                        borderColor: this.colors[i % this.colors.length],
                        borderWidth: 2,
                        fill: $wire.chartType === 'area',
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: $wire.chartType === 'horizontal-bar' ? 'y' : 'x',
                    plugins: { legend: { display: data.datasets.length > 1 } },
                    scales: type === 'pie' || type === 'doughnut' ? {} : {
                        y: { beginAtZero: true, stacked: $wire.chartType === 'stacked-bar' },
                        x: { stacked: $wire.chartType === 'stacked-bar' }
                    }
                }
            });
            this.builderSummary = data.summary;
        },

        async renderSavedChart(chartId, canvasRef, chartType) {
            const data = await $wire.getSavedChartData(chartId);
            if (this.charts[chartId]) this.charts[chartId].destroy();
            const type = this.resolveChartType(chartType);
            this.charts[chartId] = new Chart(canvasRef, {
                type: type,
                data: {
                    labels: data.labels,
                    datasets: data.datasets.map((ds, i) => ({
                        ...ds,
                        backgroundColor: this.colors[i % this.colors.length] + '99',
                        borderColor: this.colors[i % this.colors.length],
                        borderWidth: 2,
                    }))
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: data.datasets.length > 1 } }
                }
            });
        },

        init() {
            $watch('$wire.chartMetric', () => this.renderBuilderChart());
            $watch('$wire.chartDimension', () => this.renderBuilderChart());
            $watch('$wire.chartGroupBy', () => this.renderBuilderChart());
            $watch('$wire.chartType', () => this.renderBuilderChart());
            $watch('$wire.dateRange', () => this.renderBuilderChart());
        }
    }"
>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex items-center justify-between">
            <flux:heading size="xl">{{ __('Reports') }}</flux:heading>
        </div>

        @if (session('success'))
            <flux:badge color="green" class="w-full justify-center py-2">{{ session('success') }}</flux:badge>
        @endif

        {{-- Tab Navigation --}}
        <flux:tabs wire:model.live="tab">
            <flux:tab name="dashboard" icon="chart-bar-square">{{ __('Dashboard') }}</flux:tab>
            <flux:tab name="builder" icon="wrench-screwdriver">{{ __('Chart Builder') }}</flux:tab>
            <flux:tab name="archive" icon="archive-box">{{ __('Archive') }}</flux:tab>
        </flux:tabs>

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Tab 1: Dashboard --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        @if ($tab === 'dashboard')
            @if ($this->savedCharts->isEmpty())
                <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-zinc-300 py-16 dark:border-zinc-700">
                    <flux:icon name="chart-bar-square" class="mb-4 size-12 text-zinc-400" />
                    <flux:heading size="lg" class="mb-2">{{ __('No saved charts yet') }}</flux:heading>
                    <flux:text class="mb-6 text-zinc-500">{{ __('Build your first chart in the Chart Builder tab and save it to your dashboard.') }}</flux:text>
                    <flux:button variant="primary" wire:click="$set('tab', 'builder')">
                        {{ __('Open Chart Builder') }}
                    </flux:button>
                </div>
            @else
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    @foreach ($this->savedCharts as $chart)
                        <div
                            class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                            x-init="$nextTick(() => renderSavedChart({{ $chart->id }}, $refs['chart_{{ $chart->id }}'], '{{ $chart->config['chart_type'] ?? 'bar' }}'))"
                        >
                            <div class="mb-3 flex items-center justify-between">
                                <flux:heading size="sm">{{ $chart->name }}</flux:heading>
                                <div class="flex items-center gap-1">
                                    <flux:button size="xs" variant="ghost" icon="pencil" wire:click="loadChart({{ $chart->id }})" title="{{ __('Edit') }}" />
                                    <flux:button size="xs" variant="ghost" icon="eye-slash" wire:click="togglePin({{ $chart->id }})" title="{{ __('Unpin') }}" />
                                    <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteChart({{ $chart->id }})" wire:confirm="{{ __('Delete this chart?') }}" title="{{ __('Delete') }}" />
                                </div>
                            </div>
                            <div class="h-64">
                                <canvas x-ref="chart_{{ $chart->id }}"></canvas>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Unpinned charts list --}}
                @php $unpinned = $this->allSavedCharts->where('is_pinned', false); @endphp
                @if ($unpinned->isNotEmpty())
                    <div class="mt-6">
                        <flux:heading size="sm" class="mb-3">{{ __('Unpinned Charts') }}</flux:heading>
                        <div class="space-y-2">
                            @foreach ($unpinned as $chart)
                                <div class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700">
                                    <flux:text>{{ $chart->name }}</flux:text>
                                    <div class="flex items-center gap-1">
                                        <flux:button size="xs" variant="ghost" icon="eye" wire:click="togglePin({{ $chart->id }})" title="{{ __('Pin') }}" />
                                        <flux:button size="xs" variant="ghost" icon="pencil" wire:click="loadChart({{ $chart->id }})" title="{{ __('Edit') }}" />
                                        <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteChart({{ $chart->id }})" wire:confirm="{{ __('Delete this chart?') }}" title="{{ __('Delete') }}" />
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        @endif

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Tab 2: Chart Builder --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        @if ($tab === 'builder')
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                {{-- Left Panel: Controls --}}
                <div class="space-y-4 xl:col-span-1">
                    {{-- Metric --}}
                    <div>
                        <flux:select wire:model.live="chartMetric" label="{{ __('Metric') }}">
                            @foreach (\App\Services\Prospector\ReportMetricService::availableMetrics() as $key => $label)
                                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Dimension --}}
                    <div>
                        <flux:select wire:model.live="chartDimension" label="{{ __('Dimension (X-Axis)') }}">
                            @foreach (\App\Services\Prospector\ReportMetricService::availableDimensions() as $key => $label)
                                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Group By --}}
                    <div>
                        <flux:select wire:model.live="chartGroupBy" label="{{ __('Group By') }}">
                            <flux:select.option value="">{{ __('None') }}</flux:select.option>
                            @foreach (\App\Services\Prospector\ReportMetricService::availableDimensions() as $key => $label)
                                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    {{-- Chart Type --}}
                    <div>
                        <flux:text class="mb-2 text-sm font-medium">{{ __('Chart Type') }}</flux:text>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([
                                'bar' => 'Bar',
                                'line' => 'Line',
                                'area' => 'Area',
                                'pie' => 'Pie',
                                'donut' => 'Donut',
                                'stacked-bar' => 'Stacked',
                                'horizontal-bar' => 'H-Bar',
                            ] as $type => $label)
                                <flux:button
                                    size="xs"
                                    :variant="$chartType === $type ? 'primary' : 'ghost'"
                                    wire:click="$set('chartType', '{{ $type }}')"
                                >
                                    {{ $label }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Filters --}}
                    <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:text class="text-sm font-medium">{{ __('Filters') }}</flux:text>

                        <flux:select wire:model.live="dateRange" label="{{ __('Date Range') }}">
                            <flux:select.option value="today">{{ __('Today') }}</flux:select.option>
                            <flux:select.option value="yesterday">{{ __('Yesterday') }}</flux:select.option>
                            <flux:select.option value="this_week">{{ __('This Week') }}</flux:select.option>
                            <flux:select.option value="last_week">{{ __('Last Week') }}</flux:select.option>
                            <flux:select.option value="this_month">{{ __('This Month') }}</flux:select.option>
                            <flux:select.option value="last_month">{{ __('Last Month') }}</flux:select.option>
                            <flux:select.option value="last_30_days">{{ __('Last 30 Days') }}</flux:select.option>
                            <flux:select.option value="last_90_days">{{ __('Last 90 Days') }}</flux:select.option>
                            <flux:select.option value="custom">{{ __('Custom Range') }}</flux:select.option>
                        </flux:select>

                        @if ($dateRange === 'custom')
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input type="date" wire:model.live="dateFrom" label="{{ __('From') }}" />
                                <flux:input type="date" wire:model.live="dateTo" label="{{ __('To') }}" />
                            </div>
                        @endif

                        <div>
                            <flux:text class="mb-1 text-xs text-zinc-500">{{ __('Company') }}</flux:text>
                            <select wire:model.live="filterCompanyIds" multiple class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                @foreach ($this->companies as $company)
                                    <option value="{{ $company->id }}">{{ $company->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <flux:text class="mb-1 text-xs text-zinc-500">{{ __('Campaign') }}</flux:text>
                            <select wire:model.live="filterCampaignIds" multiple class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                @foreach ($this->campaigns as $campaign)
                                    <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <flux:text class="mb-1 text-xs text-zinc-500">{{ __('Agent') }}</flux:text>
                            <select wire:model.live="filterAgentIds" multiple class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                @foreach ($this->agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->user->name ?? 'Agent #' . $agent->id }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <flux:text class="mb-1 text-xs text-zinc-500">{{ __('Disposition') }}</flux:text>
                            <select wire:model.live="filterDispositions" multiple class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200">
                                @foreach ($this->dispositionOptions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <flux:select wire:model.live="filterDirection" label="{{ __('Direction') }}">
                            <flux:select.option value="">{{ __('All') }}</flux:select.option>
                            <flux:select.option value="outbound">{{ __('Outbound') }}</flux:select.option>
                            <flux:select.option value="inbound">{{ __('Inbound') }}</flux:select.option>
                        </flux:select>
                    </div>
                </div>

                {{-- Right Panel: Chart Preview --}}
                <div class="xl:col-span-2">
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                        <div class="mb-3 flex items-center justify-between">
                            <flux:heading size="sm">
                                {{ $editingChartId ? __('Editing: ') . $saveChartName : __('Preview') }}
                            </flux:heading>
                            <flux:button size="sm" variant="primary" wire:click="$set('showSaveModal', true)">
                                {{ $editingChartId ? __('Update Chart') : __('Save Chart') }}
                            </flux:button>
                        </div>

                        <div class="h-80" x-init="$nextTick(() => renderBuilderChart())">
                            <canvas x-ref="builderCanvas"></canvas>
                        </div>

                        {{-- Summary Stats --}}
                        <div class="mt-4 grid grid-cols-4 gap-4" x-show="builderSummary" x-cloak>
                            <div class="rounded-lg bg-zinc-50 p-3 text-center dark:bg-zinc-800">
                                <flux:text class="text-xs text-zinc-500">{{ __('Total') }}</flux:text>
                                <flux:text class="text-lg font-semibold" x-text="builderSummary?.total?.toLocaleString() ?? '-'"></flux:text>
                            </div>
                            <div class="rounded-lg bg-zinc-50 p-3 text-center dark:bg-zinc-800">
                                <flux:text class="text-xs text-zinc-500">{{ __('Average') }}</flux:text>
                                <flux:text class="text-lg font-semibold" x-text="builderSummary?.average?.toLocaleString() ?? '-'"></flux:text>
                            </div>
                            <div class="rounded-lg bg-zinc-50 p-3 text-center dark:bg-zinc-800">
                                <flux:text class="text-xs text-zinc-500">{{ __('Max') }}</flux:text>
                                <flux:text class="text-lg font-semibold" x-text="builderSummary?.max?.toLocaleString() ?? '-'"></flux:text>
                            </div>
                            <div class="rounded-lg bg-zinc-50 p-3 text-center dark:bg-zinc-800">
                                <flux:text class="text-xs text-zinc-500">{{ __('Min') }}</flux:text>
                                <flux:text class="text-lg font-semibold" x-text="builderSummary?.min?.toLocaleString() ?? '-'"></flux:text>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Save Chart Modal --}}
            <flux:modal wire:model="showSaveModal" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ $editingChartId ? __('Update Chart') : __('Save Chart') }}</flux:heading>
                    <flux:input wire:model="saveChartName" label="{{ __('Chart Name') }}" placeholder="{{ __('e.g. Calls by Day This Month') }}" />
                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showSaveModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="saveChart">{{ $editingChartId ? __('Update') : __('Save') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif

        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- Tab 3: Archive --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        @if ($tab === 'archive')
            {{-- Filter Bar --}}
            <div class="flex flex-wrap items-end gap-3">
                <flux:input wire:model.live.debounce.300ms="archiveSearch" placeholder="{{ __('Search name or phone...') }}" icon="magnifying-glass" class="w-64" />

                <flux:select wire:model.live="archiveCompanyId" class="w-44" placeholder="{{ __('Company') }}">
                    <flux:select.option value="">{{ __('All Companies') }}</flux:select.option>
                    @foreach ($this->companies as $company)
                        <flux:select.option value="{{ $company->id }}">{{ $company->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="archiveDisposition" class="w-44" placeholder="{{ __('Disposition') }}">
                    <flux:select.option value="">{{ __('All') }}</flux:select.option>
                    @foreach ($this->dispositionOptions as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input type="date" wire:model.live="archiveDateFrom" label="{{ __('From') }}" class="w-40" />
                <flux:input type="date" wire:model.live="archiveDateTo" label="{{ __('To') }}" class="w-40" />
            </div>

            {{-- Bulk Action Bar --}}
            @if (count($selectedArchiveIds) > 0)
                <div class="flex items-center gap-3 rounded-lg bg-blue-50 px-4 py-2 dark:bg-blue-900/20">
                    <flux:text class="text-sm font-medium">
                        {{ count($selectedArchiveIds) }} {{ __('contact(s) selected') }}
                    </flux:text>
                    <flux:button size="sm" variant="primary" wire:click="$set('showReengageModal', true)">
                        {{ __('Re-engage') }} {{ count($selectedArchiveIds) }} {{ __('contacts') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="$set('selectedArchiveIds', [])">
                        {{ __('Clear') }}
                    </flux:button>
                </div>
            @endif

            {{-- Archive Table --}}
            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-10">
                        <input
                            type="checkbox"
                            class="rounded border-zinc-300 dark:border-zinc-600"
                            x-on:change="
                                if ($event.target.checked) {
                                    $wire.set('selectedArchiveIds', @js($this->archivedContacts->pluck('id')->toArray()));
                                } else {
                                    $wire.set('selectedArchiveIds', []);
                                }
                            "
                        />
                    </flux:table.column>
                    <flux:table.column>{{ __('Contact') }}</flux:table.column>
                    <flux:table.column>{{ __('Phone') }}</flux:table.column>
                    <flux:table.column>{{ __('Company') }}</flux:table.column>
                    <flux:table.column>{{ __('Last Disposition') }}</flux:table.column>
                    <flux:table.column>{{ __('Archived') }}</flux:table.column>
                    <flux:table.column>{{ __('Total Calls') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->archivedContacts as $entry)
                        <flux:table.row>
                            <flux:table.cell>
                                <input
                                    type="checkbox"
                                    value="{{ $entry->id }}"
                                    wire:model.live="selectedArchiveIds"
                                    class="rounded border-zinc-300 dark:border-zinc-600"
                                />
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $entry->contact?->first_name }} {{ $entry->contact?->last_name }}
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $entry->contact?->phone ?? '-' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $entry->contact?->company?->name ?? '-' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($entry->last_disposition)
                                    <flux:badge size="sm" color="zinc">{{ ucfirst(str_replace('_', ' ', $entry->last_disposition)) }}</flux:badge>
                                @else
                                    <span class="text-zinc-400">-</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $entry->archived_at?->format('M j, Y') ?? '-' }}
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $entry->total_attempts ?? 0 }}
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="7" class="py-8 text-center text-zinc-500">
                                {{ __('No archived contacts found.') }}
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <div class="mt-4">
                {{ $this->archivedContacts->links() }}
            </div>

            {{-- Re-engage Modal --}}
            <flux:modal wire:model="showReengageModal" class="max-w-sm">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Re-engage Contacts') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">
                        {{ __('Select a campaign to re-enroll') }} {{ count($selectedArchiveIds) }} {{ __('contact(s) into.') }}
                    </flux:text>
                    <flux:select wire:model="reengageCampaignId" label="{{ __('Campaign') }}">
                        <flux:select.option value="">{{ __('Select campaign...') }}</flux:select.option>
                        @foreach ($this->campaigns as $campaign)
                            <flux:select.option value="{{ $campaign->id }}">{{ $campaign->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showReengageModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="reengageContacts" :disabled="!$reengageCampaignId">
                            {{ __('Re-engage') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        @endif
    </div>
</div>
