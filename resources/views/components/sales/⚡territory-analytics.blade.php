<?php

use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\TerritoryAnalyticsService;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component
{
    use WithPagination;

    public string $period = 'month';
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $filterUser = 'all';
    public string $filterTerritory = 'all';
    public string $filterStatus = 'all';
    public string $activePanel = 'overview';
    public ?int $detailId = null;

    public function mount(): void
    {
        $this->applyPeriodDates();
    }

    public function updatedPeriod(): void
    {
        if ($this->period !== 'custom') {
            $this->applyPeriodDates();
        }
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->period = 'custom';
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->period = 'custom';
        $this->resetPage();
    }

    public function updatedFilterUser(): void
    {
        $this->resetPage();
    }

    public function updatedFilterTerritory(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function drillIntoUser(int $userId): void
    {
        $this->activePanel = 'user-detail';
        $this->detailId = $userId;
        $this->resetPage();
    }

    public function drillIntoTerritory(int $territoryId): void
    {
        $this->activePanel = 'territory-detail';
        $this->detailId = $territoryId;
        $this->resetPage();
    }

    public function showCustomers(): void
    {
        $this->activePanel = 'customers';
        $this->resetPage();
    }

    public function backToOverview(): void
    {
        $this->activePanel = 'overview';
        $this->detailId = null;
        $this->resetPage();
    }

    #[Computed]
    public function service(): TerritoryAnalyticsService
    {
        return app(TerritoryAnalyticsService::class);
    }

    #[Computed]
    public function filters(): array
    {
        return array_filter([
            'dateFrom' => $this->dateFrom ?: null,
            'dateTo' => $this->dateTo ?: null,
            'userId' => $this->filterUser !== 'all' ? (int) $this->filterUser : null,
            'territoryId' => $this->filterTerritory !== 'all' ? (int) $this->filterTerritory : null,
            'status' => $this->filterStatus !== 'all' ? $this->filterStatus : null,
        ]);
    }

    #[Computed]
    public function summaryStats(): object
    {
        return $this->service->getSummaryStats($this->filters);
    }

    #[Computed]
    public function revenueStats(): object
    {
        return $this->service->getRevenueStats($this->filters);
    }

    #[Computed]
    public function conversionFunnel(): array
    {
        return $this->service->getConversionFunnel($this->filters);
    }

    #[Computed]
    public function userPerformance()
    {
        return $this->service->getUserPerformance($this->filters);
    }

    #[Computed]
    public function territoryPerformance()
    {
        return $this->service->getTerritoryPerformance($this->filters);
    }

    #[Computed]
    public function dailyTrend()
    {
        return $this->service->getDailyTrend($this->filters);
    }

    #[Computed]
    public function hourlyDistribution()
    {
        return $this->service->getHourlyDistribution($this->filters);
    }

    #[Computed]
    public function userDetail(): ?array
    {
        if ($this->activePanel !== 'user-detail' || ! $this->detailId) {
            return null;
        }

        return $this->service->getUserDetail($this->detailId, $this->filters);
    }

    #[Computed]
    public function territoryDetail(): ?array
    {
        if ($this->activePanel !== 'territory-detail' || ! $this->detailId) {
            return null;
        }

        return $this->service->getTerritoryDetail($this->detailId, $this->filters);
    }

    #[Computed]
    public function customerList()
    {
        if ($this->activePanel === 'user-detail' && $this->detailId) {
            $filters = array_merge($this->filters, ['userId' => $this->detailId]);
        } elseif ($this->activePanel === 'territory-detail' && $this->detailId) {
            $filters = array_merge($this->filters, ['territoryId' => $this->detailId]);
        } else {
            $filters = $this->filters;
        }

        return $this->service->getCustomerList($filters);
    }

    #[Computed]
    public function territories()
    {
        return SalesTerritory::active()->orderBy('name')->get();
    }

    #[Computed]
    public function users()
    {
        return User::where('type', 'internal')
            ->whereHas('doorKnocks')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function detailUser(): ?User
    {
        if ($this->activePanel !== 'user-detail' || ! $this->detailId) {
            return null;
        }

        return User::find($this->detailId);
    }

    #[Computed]
    public function detailTerritory(): ?SalesTerritory
    {
        if ($this->activePanel !== 'territory-detail' || ! $this->detailId) {
            return null;
        }

        return SalesTerritory::find($this->detailId);
    }

    private function applyPeriodDates(): void
    {
        [$start, $end] = match ($this->period) {
            'today' => [today(), today()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };

        $this->dateFrom = $start->format('Y-m-d');
        $this->dateTo = $end->format('Y-m-d');
    }
};

?>

<div>
    <div class="mx-auto max-w-7xl py-6 space-y-6">
        {{-- Header --}}
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                @if($activePanel !== 'overview')
                    <flux:button variant="subtle" size="sm" icon="arrow-left" wire:click="backToOverview" class="mb-2">
                        {{ __('Back to Overview') }}
                    </flux:button>
                @endif

                @if($activePanel === 'user-detail' && $this->detailUser)
                    <div class="flex items-center gap-3">
                        <flux:avatar :name="$this->detailUser->name" size="lg" />
                        <div>
                            <flux:heading size="xl">{{ $this->detailUser->name }}</flux:heading>
                            <flux:text class="mt-1">{{ __('Individual performance analytics') }}</flux:text>
                        </div>
                    </div>
                @elseif($activePanel === 'territory-detail' && $this->detailTerritory)
                    <div class="flex items-center gap-3">
                        <span class="h-5 w-5 rounded-full shrink-0" style="background-color: {{ $this->detailTerritory->color }}"></span>
                        <div>
                            <flux:heading size="xl">{{ $this->detailTerritory->name }}</flux:heading>
                            <flux:text class="mt-1">{{ $this->detailTerritory->description ?: __('Territory performance analytics') }}</flux:text>
                        </div>
                    </div>
                @elseif($activePanel === 'customers')
                    <flux:heading size="xl">{{ __('Customer Data') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Contact information from door knock records.') }}</flux:text>
                @else
                    <flux:heading size="xl">{{ __('Territory Analytics') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Deep-dive analytics across territories, reps, and customers.') }}</flux:text>
                @endif
            </div>

            @if($activePanel === 'overview' || $activePanel === 'customers')
                <flux:button variant="primary" size="sm" icon="users" wire:click="showCustomers" class="{{ $activePanel === 'customers' ? 'hidden' : '' }}">
                    {{ __('View Customer Data') }}
                </flux:button>
            @endif
        </div>

        {{-- Filter Bar --}}
        <div class="flex flex-wrap items-end gap-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
            <div class="grid gap-1">
                <flux:text size="sm" class="font-medium text-zinc-500">{{ __('Period') }}</flux:text>
                <flux:select wire:model.live="period" class="w-36">
                    <option value="today">{{ __('Today') }}</option>
                    <option value="week">{{ __('This Week') }}</option>
                    <option value="month">{{ __('This Month') }}</option>
                    <option value="quarter">{{ __('This Quarter') }}</option>
                    <option value="year">{{ __('This Year') }}</option>
                    <option value="custom">{{ __('Custom') }}</option>
                </flux:select>
            </div>

            @if($period === 'custom')
                <div class="grid gap-1">
                    <flux:text size="sm" class="font-medium text-zinc-500">{{ __('From') }}</flux:text>
                    <flux:input type="date" wire:model.live.debounce.300ms="dateFrom" class="w-40" />
                </div>
                <div class="grid gap-1">
                    <flux:text size="sm" class="font-medium text-zinc-500">{{ __('To') }}</flux:text>
                    <flux:input type="date" wire:model.live.debounce.300ms="dateTo" class="w-40" />
                </div>
            @endif

            <div class="grid gap-1">
                <flux:text size="sm" class="font-medium text-zinc-500">{{ __('Rep') }}</flux:text>
                <flux:select wire:model.live="filterUser" class="w-44">
                    <option value="all">{{ __('All Reps') }}</option>
                    @foreach($this->users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-1">
                <flux:text size="sm" class="font-medium text-zinc-500">{{ __('Territory') }}</flux:text>
                <flux:select wire:model.live="filterTerritory" class="w-44">
                    <option value="all">{{ __('All Territories') }}</option>
                    @foreach($this->territories as $territory)
                        <option value="{{ $territory->id }}">{{ $territory->name }}</option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-1">
                <flux:text size="sm" class="font-medium text-zinc-500">{{ __('Status') }}</flux:text>
                <flux:select wire:model.live="filterStatus" class="w-40">
                    <option value="all">{{ __('All Statuses') }}</option>
                    @foreach(DoorKnock::STATUSES as $key => $label)
                        <option value="{{ $key }}">{{ __($label) }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- PANEL: Overview --}}
        @if($activePanel === 'overview')
            @php
                $stats = $this->summaryStats;
                $revenue = $this->revenueStats;
                $convRate = $stats->total > 0 ? round(($stats->conversions / $stats->total) * 100, 1) : 0;
                $apptRate = $stats->total > 0 ? round(($stats->appointments / $stats->total) * 100, 1) : 0;
            @endphp

            {{-- KPI Cards --}}
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-4">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Total Knocks') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ number_format($stats->total) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Unique Addresses') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ number_format($stats->unique_addresses) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Conversion Rate') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $convRate }}%</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Appointment Rate') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $apptRate }}%</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Revenue') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">${{ number_format($revenue->total_estimated ?? 0) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Avg Deal Value') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">${{ number_format($revenue->avg_deal_value ?? 0) }}</flux:heading>
                </div>
            </div>

            {{-- Conversion Funnel --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Conversion Funnel') }}</flux:heading>

                <div class="space-y-3">
                    @foreach($this->conversionFunnel as $step)
                        @php
                            $maxCount = $this->conversionFunnel[0]['count'] ?? 1;
                            $barWidth = $maxCount > 0 ? max(($step['count'] / $maxCount) * 100, 2) : 2;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="font-medium">{{ __($step['label']) }}</span>
                                <span class="text-zinc-500">{{ number_format($step['count']) }} ({{ $step['pct'] }}%)</span>
                            </div>
                            <div class="h-6 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <div
                                    class="h-full rounded-full bg-blue-500 dark:bg-blue-400 transition-all flex items-center justify-end pr-2"
                                    style="width: {{ $barWidth }}%"
                                >
                                    @if($step['count'] > 0 && $barWidth > 10)
                                        <span class="text-xs font-medium text-white">{{ number_format($step['count']) }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Trend Charts --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Knocks per Day --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:heading size="lg" class="mb-4">{{ __('Knocks per Day') }}</flux:heading>

                    @if($this->dailyTrend->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('No data for this period.') }}</flux:text>
                    @else
                        @php $maxDay = max($this->dailyTrend->values()->all() ?: [1]); @endphp
                        <div class="flex items-end gap-1 h-32">
                            @foreach($this->dailyTrend as $date => $count)
                                <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
                                    <flux:text size="xs" class="font-mono truncate">{{ $count }}</flux:text>
                                    <div
                                        class="w-full rounded-t bg-blue-500 dark:bg-blue-400 transition-all"
                                        style="height: {{ $maxDay > 0 ? ($count / $maxDay) * 100 : 0 }}%"
                                    ></div>
                                    <flux:text size="xs" class="text-zinc-400 truncate">
                                        {{ Carbon::parse($date)->format('m/d') }}
                                    </flux:text>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Knocks by Hour --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:heading size="lg" class="mb-4">{{ __('Knocks by Hour') }}</flux:heading>

                    @if($this->hourlyDistribution->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('No data for this period.') }}</flux:text>
                    @else
                        @php $maxHour = max($this->hourlyDistribution->values()->all() ?: [1]); @endphp
                        <div class="flex items-end gap-1 h-32">
                            @for($h = 6; $h <= 21; $h++)
                                @php $count = $this->hourlyDistribution[$h] ?? 0; @endphp
                                <div class="flex-1 flex flex-col items-center gap-1 min-w-0">
                                    @if($count > 0)
                                        <flux:text size="xs" class="font-mono truncate">{{ $count }}</flux:text>
                                    @else
                                        <span class="text-xs">&nbsp;</span>
                                    @endif
                                    <div
                                        class="w-full rounded-t bg-green-500 dark:bg-green-400 transition-all"
                                        style="height: {{ $maxHour > 0 ? ($count / $maxHour) * 100 : 0 }}%"
                                    ></div>
                                    <flux:text size="xs" class="text-zinc-400 truncate">
                                        {{ $h > 12 ? ($h - 12) . 'p' : ($h === 12 ? '12p' : $h . 'a') }}
                                    </flux:text>
                                </div>
                            @endfor
                        </div>
                    @endif
                </div>
            </div>

            {{-- User Performance Table --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Rep Performance') }}</flux:heading>

                @if($this->userPerformance->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('No knocks recorded in this period.') }}</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">#</th>
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Rep') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Knocks') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Interested') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Appts') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Deals') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Revenue') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Conv %') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($this->userPerformance as $i => $entry)
                                    <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors" wire:click="drillIntoUser({{ $entry->user?->id }})">
                                        <td class="py-2.5 pr-4">
                                            @if($i === 0)
                                                <span class="text-lg">&#x1f947;</span>
                                            @elseif($i === 1)
                                                <span class="text-lg">&#x1f948;</span>
                                            @elseif($i === 2)
                                                <span class="text-lg">&#x1f949;</span>
                                            @else
                                                <span class="text-zinc-400">{{ $i + 1 }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 pr-4">
                                            <div class="flex items-center gap-2">
                                                <flux:avatar :name="$entry->user?->name ?? 'Unknown'" size="xs" />
                                                <span class="font-medium">{{ $entry->user?->name ?? __('Unknown') }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 pr-4 text-right font-mono">{{ $entry->total_knocks }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono text-green-600">{{ $entry->conversions }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono text-blue-600">{{ $entry->appointments }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono text-yellow-600">{{ $entry->deals }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono">${{ number_format($entry->revenue) }}</td>
                                        <td class="py-2.5 text-right">
                                            <flux:badge size="sm" :color="$entry->conversion_rate >= 20 ? 'green' : ($entry->conversion_rate >= 10 ? 'yellow' : 'zinc')">
                                                {{ $entry->conversion_rate }}%
                                            </flux:badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Territory Performance Table --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Territory Performance') }}</flux:heading>

                @if($this->territoryPerformance->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('No knocks recorded in this period.') }}</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Territory') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Knocks') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Unique Addr') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Conversions') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Revenue') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Conv %') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($this->territoryPerformance as $entry)
                                    <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors" wire:click="drillIntoTerritory({{ $entry->territory?->id }})">
                                        <td class="py-2.5 pr-4">
                                            <div class="flex items-center gap-2">
                                                <span class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $entry->territory?->color ?? '#9CA3AF' }}"></span>
                                                <span class="font-medium">{{ $entry->territory?->name ?? __('Unknown') }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 pr-4 text-right font-mono">{{ $entry->total_knocks }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono">{{ $entry->unique_addresses }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono text-green-600">{{ $entry->conversions }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono">${{ number_format($entry->revenue) }}</td>
                                        <td class="py-2.5 text-right">
                                            <flux:badge size="sm" :color="$entry->conversion_rate >= 20 ? 'green' : ($entry->conversion_rate >= 10 ? 'yellow' : 'zinc')">
                                                {{ $entry->conversion_rate }}%
                                            </flux:badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        @endif

        {{-- PANEL: User Detail --}}
        @if($activePanel === 'user-detail' && $this->userDetail)
            @php
                $ud = $this->userDetail;
                $udStats = $ud['stats'];
                $udRevenue = $ud['revenue'];
                $udConvRate = $udStats->total > 0 ? round(($udStats->conversions / $udStats->total) * 100, 1) : 0;
            @endphp

            {{-- KPI Cards --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Total Knocks') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ number_format($udStats->total) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Daily Average') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $ud['daily_average'] }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Best Day') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $ud['best_day'] ? $ud['best_day']['count'] . ' knocks' : '-' }}</flux:heading>
                    @if($ud['best_day'])
                        <flux:text size="sm" class="text-zinc-400">{{ Carbon::parse($ud['best_day']['date'])->format('M j') }}</flux:text>
                    @endif
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Revenue') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">${{ number_format($udRevenue->total_estimated ?? 0) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Conversion Rate') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $udConvRate }}%</flux:heading>
                </div>
            </div>

            {{-- Status Breakdown --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Status Breakdown') }}</flux:heading>
                <div class="space-y-3">
                    @foreach(DoorKnock::STATUSES as $key => $label)
                        @php
                            $count = (int) ($udStats->$key ?? 0);
                            $pct = $udStats->total > 0 ? ($count / $udStats->total) * 100 : 0;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ DoorKnock::STATUS_COLORS[$key] }}"></span>
                                    <span>{{ __($label) }}</span>
                                </div>
                                <span class="font-medium">{{ $count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    style="width: {{ $pct }}%; background-color: {{ DoorKnock::STATUS_COLORS[$key] }}"
                                ></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {{-- Territories Worked --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:heading size="lg" class="mb-4">{{ __('Territories Worked') }}</flux:heading>

                    @if($ud['territories']->isEmpty())
                        <flux:text class="text-zinc-500">{{ __('No territory data.') }}</flux:text>
                    @else
                        <div class="space-y-3">
                            @foreach($ud['territories'] as $tw)
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="h-3 w-3 rounded-full" style="background-color: {{ $tw->territory?->color ?? '#9CA3AF' }}"></span>
                                        <flux:text>{{ $tw->territory?->name ?? __('Unknown') }}</flux:text>
                                    </div>
                                    <flux:badge size="sm">{{ $tw->knock_count }} {{ __('knocks') }}</flux:badge>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Quick Stats --}}
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:heading size="lg" class="mb-4">{{ __('Activity Summary') }}</flux:heading>
                    <div class="space-y-4">
                        <div class="flex items-center justify-between">
                            <flux:text>{{ __('Days Active') }}</flux:text>
                            <flux:text class="font-medium">{{ $ud['days_active'] }}</flux:text>
                        </div>
                        <div class="flex items-center justify-between">
                            <flux:text>{{ __('Unique Addresses') }}</flux:text>
                            <flux:text class="font-medium">{{ number_format($udStats->unique_addresses) }}</flux:text>
                        </div>
                        <div class="flex items-center justify-between">
                            <flux:text>{{ __('Deals Created') }}</flux:text>
                            <flux:text class="font-medium">{{ $udRevenue->deal_count ?? 0 }}</flux:text>
                        </div>
                        <div class="flex items-center justify-between">
                            <flux:text>{{ __('Deals Signed') }}</flux:text>
                            <flux:text class="font-medium">{{ $udRevenue->signed_count ?? 0 }}</flux:text>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Customer List for this user --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Knock History') }}</flux:heading>
                @include('components.sales._analytics-customer-table', ['knocks' => $this->customerList])
            </div>
        @endif

        {{-- PANEL: Territory Detail --}}
        @if($activePanel === 'territory-detail' && $this->territoryDetail)
            @php
                $td = $this->territoryDetail;
                $tdStats = $td['stats'];
                $tdRevenue = $td['revenue'];
                $tdConvRate = $tdStats->total > 0 ? round(($tdStats->conversions / $tdStats->total) * 100, 1) : 0;
            @endphp

            {{-- KPI Cards --}}
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Total Knocks') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ number_format($tdStats->total) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Unique Addresses') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ number_format($tdStats->unique_addresses) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Repeat Visits') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ number_format($td['repeat_visits']) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Revenue') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">${{ number_format($tdRevenue->total_estimated ?? 0) }}</flux:heading>
                </div>
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                    <flux:text size="sm" class="text-zinc-500">{{ __('Conversion Rate') }}</flux:text>
                    <flux:heading size="xl" class="mt-1">{{ $tdConvRate }}%</flux:heading>
                </div>
            </div>

            {{-- Rep Performance in this Territory --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Rep Performance') }}</flux:heading>

                @if($td['reps']->isEmpty())
                    <flux:text class="text-zinc-500">{{ __('No rep data.') }}</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Rep') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Knocks') }}</th>
                                    <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Conversions') }}</th>
                                    <th class="text-right py-2 font-medium text-zinc-500">{{ __('Conv %') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach($td['reps'] as $rep)
                                    <tr class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors" wire:click="drillIntoUser({{ $rep->user?->id }})">
                                        <td class="py-2.5 pr-4">
                                            <div class="flex items-center gap-2">
                                                <flux:avatar :name="$rep->user?->name ?? 'Unknown'" size="xs" />
                                                <span class="font-medium">{{ $rep->user?->name ?? __('Unknown') }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 pr-4 text-right font-mono">{{ $rep->knock_count }}</td>
                                        <td class="py-2.5 pr-4 text-right font-mono text-green-600">{{ $rep->conversions }}</td>
                                        <td class="py-2.5 text-right">
                                            <flux:badge size="sm" :color="$rep->conversion_rate >= 20 ? 'green' : ($rep->conversion_rate >= 10 ? 'yellow' : 'zinc')">
                                                {{ $rep->conversion_rate }}%
                                            </flux:badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Customer List for this territory --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Knock History') }}</flux:heading>
                @include('components.sales._analytics-customer-table', ['knocks' => $this->customerList])
            </div>
        @endif

        {{-- PANEL: Customers --}}
        @if($activePanel === 'customers')
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                @include('components.sales._analytics-customer-table', ['knocks' => $this->customerList])
            </div>
        @endif
    </div>
</div>
