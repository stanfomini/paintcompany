<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerConversation;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $dateRange = '30d';

    public ?string $startDate = null;

    public ?string $endDate = null;

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->dateRange = '30d';
    }

    #[Computed]
    public function reportData(): array
    {
        [$start, $end] = $this->getDateFilter();
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        $flows = MessengerBotFlow::with(['conversations' => fn ($q) =>
            $q->whereBetween('started_at', [$startDate, $endDate])
        ])->get();

        $flowsData = [];

        foreach ($flows as $flow) {
            $conversations = $flow->conversations;
            $total = $conversations->count();
            $completed = $conversations->where('status', 'completed')->count();
            $abandoned = $conversations->where('status', 'abandoned')->count();
            $humanTakeover = $conversations->where('status', 'human_takeover')->count();
            $inProgress = $conversations->where('status', 'in_progress')->count();
            $leads = $conversations->whereNotNull('customer_id')->count();

            $completionRate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;

            // Average duration for completed conversations
            $completedConvos = $conversations->where('status', 'completed')
                ->filter(fn ($c) => $c->started_at && $c->completed_at);
            $avgDurationMinutes = $completedConvos->count() > 0
                ? round($completedConvos->avg(fn ($c) => $c->started_at->diffInMinutes($c->completed_at)), 1)
                : null;

            // Entry source breakdown
            $bySource = $conversations->groupBy(fn ($c) => $c->entry_source ?? 'default');
            $sourceBreakdown = [];
            foreach (['keyword', 'ad', 'default'] as $source) {
                $sourceConvos = $bySource->get($source, collect());
                $sourceTotal = $sourceConvos->count();
                $sourceCompleted = $sourceConvos->where('status', 'completed')->count();
                $sourceRate = $sourceTotal > 0 ? round(($sourceCompleted / $sourceTotal) * 100, 1) : 0;

                $sourceBreakdown[$source] = [
                    'started' => $sourceTotal,
                    'completed' => $sourceCompleted,
                    'rate' => $sourceRate,
                ];
            }

            $flowsData[] = [
                'id' => $flow->id,
                'name' => $flow->name,
                'status' => $flow->status,
                'trigger_keyword' => $flow->trigger_keyword,
                'total' => $total,
                'completed' => $completed,
                'abandoned' => $abandoned,
                'human_takeover' => $humanTakeover,
                'in_progress' => $inProgress,
                'completion_rate' => $completionRate,
                'avg_duration_minutes' => $avgDurationMinutes,
                'leads' => $leads,
                'source_breakdown' => $sourceBreakdown,
            ];
        }

        return $flowsData;
    }

    #[Computed]
    public function globalSummary(): array
    {
        [$start, $end] = $this->getDateFilter();
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        $conversations = MessengerConversation::whereBetween('started_at', [$startDate, $endDate])->get();
        $total = $conversations->count();
        $completed = $conversations->where('status', 'completed')->count();
        $leads = $conversations->whereNotNull('customer_id')->count();
        $activeFlows = MessengerBotFlow::active()->count();

        return [
            'active_flows' => $activeFlows,
            'total' => $total,
            'completed' => $completed,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'leads' => $leads,
        ];
    }

    #[Computed]
    public function dailyTrend(): array
    {
        [$start, $end] = $this->getDateFilter();
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        $rows = MessengerConversation::whereBetween('started_at', [$startDate, $endDate])
            ->selectRaw("DATE(started_at) as date,
                COUNT(*) as started,
                SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status='abandoned' THEN 1 ELSE 0 END) as abandoned")
            ->groupByRaw('DATE(started_at)')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        $days = [];
        $current = $startDate->copy();
        $limit = $endDate->copy()->startOfDay();

        while ($current->lte($limit)) {
            $dateStr = $current->toDateString();
            $row = $rows->get($dateStr);

            $started = (int) ($row?->started ?? 0);
            $completed = (int) ($row?->completed ?? 0);
            $abandoned = (int) ($row?->abandoned ?? 0);

            $days[] = [
                'date' => $dateStr,
                'formatted' => $current->format('M j'),
                'started' => $started,
                'completed' => $completed,
                'abandoned' => $abandoned,
                'completion_rate' => $started > 0 ? round(($completed / $started) * 100, 1) : 0,
            ];

            $current = $current->addDay();
        }

        return $days;
    }

    public function setDateRange(string $range): void
    {
        if (in_array($range, ['7d', '30d', '90d', 'custom'])) {
            $this->dateRange = $range;

            if ($range !== 'custom') {
                $this->startDate = null;
                $this->endDate = null;
            }

            unset($this->reportData, $this->dailyTrend, $this->globalSummary);
        }
    }

    public function updatedStartDate(): void
    {
        $this->dateRange = 'custom';
        unset($this->reportData, $this->dailyTrend, $this->globalSummary);
    }

    public function updatedEndDate(): void
    {
        $this->dateRange = 'custom';
        unset($this->reportData, $this->dailyTrend, $this->globalSummary);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function getDateFilter(): array
    {
        if ($this->dateRange === 'custom' && $this->startDate && $this->endDate) {
            return [
                Carbon::parse($this->startDate),
                Carbon::parse($this->endDate),
            ];
        }

        $end = now();
        $start = match ($this->dateRange) {
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            default => now()->subDays(30),
        };

        return [$start, $end];
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">{{ __('Messenger Bot Performance') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Bot flow completion rates, lead generation, and entry source tracking.') }}
            </flux:text>
        </div>

        {{-- Date Range Pills --}}
        <div class="flex items-center gap-2">
            <div class="flex gap-1 rounded-lg bg-zinc-100 p-1 dark:bg-zinc-800">
                @foreach (['7d' => '7d', '30d' => '30d', '90d' => '90d', 'custom' => 'Custom'] as $range => $label)
                    <button
                        wire:key="range-{{ $range }}"
                        wire:click="setDateRange('{{ $range }}')"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-all
                            {{ $dateRange === $range
                                ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100'
                                : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Custom Date Range Inputs --}}
    @if ($dateRange === 'custom')
        <div class="mb-6 flex items-end gap-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:input
                wire:model.live="startDate"
                type="date"
                :label="__('Start Date')"
            />
            <flux:input
                wire:model.live="endDate"
                type="date"
                :label="__('End Date')"
            />
        </div>
    @endif

    @php $summary = $this->globalSummary; @endphp

    {{-- Global Summary Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Active Flows') }}</flux:text>
                <flux:icon name="bolt" variant="mini" class="size-4 text-blue-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ $summary['active_flows'] }}</span>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Conversations') }}</flux:text>
                <flux:icon name="chat-bubble-left-right" variant="mini" class="size-4 text-purple-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($summary['total']) }}</span>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</flux:text>
                <flux:icon name="check-circle" variant="mini" class="size-4 text-green-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold text-green-600 dark:text-green-400">{{ number_format($summary['completed']) }}</span>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Completion Rate') }}</flux:text>
                <flux:icon name="arrow-trending-up" variant="mini" class="size-4 text-amber-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold {{ $summary['completion_rate'] >= 50 ? 'text-green-600 dark:text-green-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                    {{ $summary['completion_rate'] }}%
                </span>
            </div>
        </div>

        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Leads Created') }}</flux:text>
                <flux:icon name="user-plus" variant="mini" class="size-4 text-indigo-500" />
            </div>
            <div class="mt-2">
                <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($summary['leads']) }}</span>
            </div>
        </div>
    </div>

    @php $report = $this->reportData; @endphp

    @if (empty($report))
        {{-- Empty State --}}
        <flux:callout variant="outline" icon="chat-bubble-left-right">
            <flux:callout.heading>{{ __('No Bot Flows Found') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Create messenger bot flows to start tracking conversation performance.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Per-Flow Cards --}}
        <div class="space-y-6">
            @foreach ($report as $flowData)
                @php
                    $flowId = $flowData['id'];
                @endphp
                <div wire:key="flow-{{ $flowId }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    {{-- Flow Header --}}
                    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <flux:heading size="base">{{ $flowData['name'] }}</flux:heading>
                            @if ($flowData['trigger_keyword'])
                                <flux:badge color="purple" size="sm">{{ $flowData['trigger_keyword'] }}</flux:badge>
                            @endif
                            @if ($flowData['status'] === 'active')
                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ ucfirst($flowData['status']) }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                            <span>{{ number_format($flowData['total']) }} {{ __('started') }}</span>
                            <span>{{ number_format($flowData['completed']) }} {{ __('completed') }}</span>
                            <span class="font-medium {{ $flowData['completion_rate'] >= 50 ? 'text-green-600 dark:text-green-400' : '' }}">
                                {{ $flowData['completion_rate'] }}%
                            </span>
                        </div>
                    </div>

                    @if ($flowData['total'] > 0)
                        <div class="overflow-x-auto px-6 py-4">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="pb-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Metric') }}</th>
                                        <th class="pb-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Count') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Conversations Started') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ number_format($flowData['total']) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Completed') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-green-600 dark:text-green-400">{{ number_format($flowData['completed']) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Abandoned') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-red-600 dark:text-red-400">{{ number_format($flowData['abandoned']) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Human Takeover') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-amber-600 dark:text-amber-400">{{ number_format($flowData['human_takeover']) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('In Progress') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-blue-600 dark:text-blue-400">{{ number_format($flowData['in_progress']) }}</td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Completion Rate') }}</td>
                                        <td class="py-3 text-right text-sm font-bold {{ $flowData['completion_rate'] >= 50 ? 'text-green-600 dark:text-green-400' : 'text-zinc-900 dark:text-zinc-100' }}">{{ $flowData['completion_rate'] }}%</td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Avg Duration') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            @if ($flowData['avg_duration_minutes'] !== null)
                                                {{ $flowData['avg_duration_minutes'] }} {{ __('min') }}
                                            @else
                                                <span class="text-zinc-400 dark:text-zinc-500">&mdash;</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="py-3 text-sm text-zinc-700 dark:text-zinc-300">{{ __('Leads Created') }}</td>
                                        <td class="py-3 text-right text-sm font-medium text-indigo-600 dark:text-indigo-400">{{ number_format($flowData['leads']) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        {{-- Entry Source Breakdown --}}
                        <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-700">
                            <flux:text class="mb-3 text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Entry Source Breakdown') }}</flux:text>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                @foreach ([
                                    'keyword' => ['label' => __('Keyword'), 'icon' => 'hashtag', 'color' => 'purple'],
                                    'ad' => ['label' => __('Ad'), 'icon' => 'megaphone', 'color' => 'blue'],
                                    'default' => ['label' => __('Default'), 'icon' => 'chat-bubble-left', 'color' => 'zinc'],
                                ] as $sourceKey => $sourceInfo)
                                    @php $src = $flowData['source_breakdown'][$sourceKey]; @endphp
                                    <div class="rounded-lg border border-zinc-100 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/50">
                                        <div class="flex items-center gap-2">
                                            <flux:icon :name="$sourceInfo['icon']" variant="mini" class="size-3.5 text-zinc-400" />
                                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">{{ $sourceInfo['label'] }}</span>
                                        </div>
                                        <div class="mt-2 flex items-baseline gap-2">
                                            <span class="text-lg font-bold text-zinc-900 dark:text-zinc-100">{{ $src['started'] }}</span>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('started') }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ $src['completed'] }} {{ __('completed') }}
                                            @if ($src['started'] > 0)
                                                <span class="font-medium {{ $src['rate'] >= 50 ? 'text-green-600 dark:text-green-400' : '' }}">({{ $src['rate'] }}%)</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <div class="px-6 py-8 text-center">
                            <flux:icon name="chat-bubble-left-right" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('No conversations recorded for this flow in the selected date range.') }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Daily Trend --}}
        @php $trend = $this->dailyTrend; @endphp
        @if (! empty($trend))
            <div class="mt-6 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                    <flux:heading size="base">{{ __('Daily Trend') }}</flux:heading>
                    <flux:text class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ __('Conversations started, completed, and abandoned by day.') }}
                    </flux:text>
                </div>

                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Started') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Abandoned') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Completion Rate') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach (array_reverse($trend) as $idx => $day)
                                <tr wire:key="day-{{ $idx }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $day['formatted'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">{{ $day['started'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $day['completed'] > 0 ? 'font-medium text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $day['completed'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $day['abandoned'] > 0 ? 'font-medium text-red-600 dark:text-red-400' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $day['abandoned'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $day['completion_rate'] >= 50 ? 'font-medium text-green-600 dark:text-green-400' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $day['completion_rate'] }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
