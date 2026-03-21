<?php

use App\Models\FbLandingPageOffer;
use App\Models\FbLandingPageView;
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

        $offers = FbLandingPageOffer::with(['views' => function ($q) use ($start, $end) {
            $q->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);
        }])->get();

        $offersData = [];

        foreach ($offers as $offer) {
            $variants = [];

            foreach (['a', 'b'] as $variantKey) {
                $variantViews = $offer->views->where('variant', $variantKey);
                $totalViews = $variantViews->count();
                $uniqueVisitors = $variantViews->unique('visitor_id')->count();
                $conversions = $variantViews->where('converted', true)->count();
                $conversionRate = $uniqueVisitors > 0
                    ? round(($conversions / $uniqueVisitors) * 100, 2)
                    : 0;

                $variants[$variantKey] = [
                    'total_views' => $totalViews,
                    'unique_visitors' => $uniqueVisitors,
                    'conversions' => $conversions,
                    'conversion_rate' => $conversionRate,
                ];
            }

            $winner = null;
            if ($variants['a']['conversion_rate'] > 0 || $variants['b']['conversion_rate'] > 0) {
                if ($variants['a']['conversion_rate'] > $variants['b']['conversion_rate']) {
                    $winner = 'a';
                } elseif ($variants['b']['conversion_rate'] > $variants['a']['conversion_rate']) {
                    $winner = 'b';
                }
            }

            $allViews = $offer->views;
            $totalViews = $allViews->count();
            $totalUnique = $allViews->unique('visitor_id')->count();
            $totalConversions = $allViews->where('converted', true)->count();
            $totalConversionRate = $totalUnique > 0
                ? round(($totalConversions / $totalUnique) * 100, 2)
                : 0;

            $offersData[] = [
                'id' => $offer->id,
                'title' => $offer->title,
                'is_active' => $offer->is_active,
                'variants' => $variants,
                'winner' => $winner,
                'summary' => [
                    'total_views' => $totalViews,
                    'unique_visitors' => $totalUnique,
                    'conversions' => $totalConversions,
                    'conversion_rate' => $totalConversionRate,
                ],
            ];
        }

        return $offersData;
    }

    #[Computed]
    public function dailyTrend(): array
    {
        [$start, $end] = $this->getDateFilter();
        $startDate = $start->copy()->startOfDay();
        $endDate = $end->copy()->endOfDay();

        $views = FbLandingPageView::query()
            ->whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('DATE(created_at) as date, variant, COUNT(*) as view_count, COUNT(DISTINCT visitor_id) as unique_count, SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) as conversions')
            ->groupByRaw('DATE(created_at), variant')
            ->orderBy('date')
            ->get();

        $days = [];
        $current = $startDate->copy();
        $limit = $endDate->copy()->startOfDay();

        while ($current->lte($limit)) {
            $dateStr = $current->toDateString();
            $dayViewsA = $views->where('date', $dateStr)->where('variant', 'a')->first();
            $dayViewsB = $views->where('date', $dateStr)->where('variant', 'b')->first();

            $days[] = [
                'date' => $dateStr,
                'formatted' => $current->format('M j'),
                'a_views' => (int) ($dayViewsA?->view_count ?? 0),
                'b_views' => (int) ($dayViewsB?->view_count ?? 0),
                'a_unique' => (int) ($dayViewsA?->unique_count ?? 0),
                'b_unique' => (int) ($dayViewsB?->unique_count ?? 0),
                'a_conversions' => (int) ($dayViewsA?->conversions ?? 0),
                'b_conversions' => (int) ($dayViewsB?->conversions ?? 0),
            ];

            $current = $current->addDay();
        }

        return $days;
    }

    #[Computed]
    public function globalSummary(): array
    {
        $data = $this->reportData;

        $totalViews = 0;
        $totalUnique = 0;
        $totalConversions = 0;

        foreach ($data as $offerData) {
            $totalViews += $offerData['summary']['total_views'];
            $totalUnique += $offerData['summary']['unique_visitors'];
            $totalConversions += $offerData['summary']['conversions'];
        }

        return [
            'total_views' => $totalViews,
            'unique_visitors' => $totalUnique,
            'conversions' => $totalConversions,
            'conversion_rate' => $totalUnique > 0 ? round(($totalConversions / $totalUnique) * 100, 2) : 0,
            'offers_count' => count($data),
        ];
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
            <flux:heading size="lg">{{ __('Facebook Ads Performance') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('A/B test results and conversion tracking for landing page offers.') }}
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

    @php $report = $this->reportData; @endphp

    @if (empty($report))
        {{-- Empty State --}}
        <flux:callout variant="outline" icon="chart-bar">
            <flux:callout.heading>{{ __('No Facebook Offers Found') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Create landing page offers to start tracking A/B test performance.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        {{-- Global Summary Cards --}}
        @php $summary = $this->globalSummary; @endphp
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Offers') }}</flux:text>
                    <flux:icon name="megaphone" variant="mini" class="size-4 text-blue-500" />
                </div>
                <div class="mt-2">
                    <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ $summary['offers_count'] }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Page Views') }}</flux:text>
                    <flux:icon name="eye" variant="mini" class="size-4 text-purple-500" />
                </div>
                <div class="mt-2">
                    <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($summary['total_views']) }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Unique Visitors') }}</flux:text>
                    <flux:icon name="users" variant="mini" class="size-4 text-indigo-500" />
                </div>
                <div class="mt-2">
                    <span class="text-xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($summary['unique_visitors']) }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Conversions') }}</flux:text>
                    <flux:icon name="check-circle" variant="mini" class="size-4 text-green-500" />
                </div>
                <div class="mt-2">
                    <span class="text-xl font-bold text-green-600 dark:text-green-400">{{ number_format($summary['conversions']) }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Conv. Rate') }}</flux:text>
                    <flux:icon name="arrow-trending-up" variant="mini" class="size-4 text-amber-500" />
                </div>
                <div class="mt-2">
                    <span class="text-xl font-bold {{ $summary['conversion_rate'] >= 5 ? 'text-green-600 dark:text-green-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                        {{ $summary['conversion_rate'] }}%
                    </span>
                </div>
            </div>
        </div>

        {{-- Per-Offer Cards --}}
        <div class="space-y-6">
            @foreach ($report as $offerData)
                @php
                    $offerId = $offerData['id'];
                    $offerTitle = $offerData['title'];
                    $offerIsActive = $offerData['is_active'];
                    $variants = $offerData['variants'];
                    $winner = $offerData['winner'];
                    $offerSummary = $offerData['summary'];
                @endphp
                <div wire:key="offer-{{ $offerId }}" class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                    {{-- Offer Header --}}
                    <div class="flex items-center justify-between border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                        <div class="flex items-center gap-3">
                            <flux:heading size="base">{{ $offerTitle }}</flux:heading>
                            @if ($offerIsActive)
                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Inactive') }}</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400">
                            <span>{{ number_format($offerSummary['total_views']) }} {{ __('views') }}</span>
                            <span>{{ number_format($offerSummary['conversions']) }} {{ __('conv.') }}</span>
                            <span class="font-medium {{ $offerSummary['conversion_rate'] >= 5 ? 'text-green-600 dark:text-green-400' : '' }}">
                                {{ $offerSummary['conversion_rate'] }}%
                            </span>
                        </div>
                    </div>

                    {{-- A/B Comparison Table --}}
                    @if ($offerSummary['total_views'] > 0)
                        <div class="overflow-x-auto px-6 py-4">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                        <th class="pb-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            {{ __('Metric') }}
                                        </th>
                                        <th class="pb-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            {{ __('Variant A (Photo)') }}
                                            @if ($winner === 'a')
                                                <span class="text-amber-500">&#9733;</span>
                                            @endif
                                        </th>
                                        <th class="pb-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            {{ __('Variant B (Video)') }}
                                            @if ($winner === 'b')
                                                <span class="text-amber-500">&#9733;</span>
                                            @endif
                                        </th>
                                        <th class="pb-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                            {{ __('Diff') }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @php
                                        $metrics = [
                                            ['label' => __('Page Views'), 'key' => 'total_views'],
                                            ['label' => __('Unique Visitors'), 'key' => 'unique_visitors'],
                                            ['label' => __('Conversions'), 'key' => 'conversions'],
                                            ['label' => __('Conversion Rate'), 'key' => 'conversion_rate', 'suffix' => '%'],
                                        ];
                                    @endphp
                                    @foreach ($metrics as $metric)
                                        @php
                                            $aVal = $variants['a'][$metric['key']];
                                            $bVal = $variants['b'][$metric['key']];
                                            $diff = round($bVal - $aVal, 2);
                                            $suffix = $metric['suffix'] ?? '';
                                            $isRate = $metric['key'] === 'conversion_rate';
                                        @endphp
                                        <tr wire:key="offer-{{ $offerId }}-metric-{{ $metric['key'] }}">
                                            <td class="py-3 text-sm {{ $isRate ? 'font-medium' : '' }} text-zinc-700 dark:text-zinc-300">{{ $metric['label'] }}</td>
                                            <td class="py-3 text-right text-sm {{ $isRate ? 'font-bold' : 'font-medium' }} {{ $winner === 'a' ? 'text-green-700 dark:text-green-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                                                {{ $isRate ? $aVal . $suffix : number_format($aVal) }}
                                            </td>
                                            <td class="py-3 text-right text-sm {{ $isRate ? 'font-bold' : 'font-medium' }} {{ $winner === 'b' ? 'text-green-700 dark:text-green-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                                                {{ $isRate ? $bVal . $suffix : number_format($bVal) }}
                                            </td>
                                            <td class="py-3 text-right text-sm text-zinc-500 dark:text-zinc-400">
                                                <span class="{{ $diff > 0 ? 'text-green-600 dark:text-green-400' : ($diff < 0 ? 'text-red-600 dark:text-red-400' : '') }}">
                                                    {{ $diff > 0 ? '+' : '' }}{{ $isRate ? $diff . $suffix : number_format($diff) }}
                                                </span>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        {{-- Winner callout --}}
                        @if ($winner)
                            <div class="border-t border-zinc-200 px-6 py-3 dark:border-zinc-700">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-amber-500">&#9733;</span>
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                        @if ($winner === 'a')
                                            {{ __('Variant A (Photo) is winning with :rate% conversion rate.', ['rate' => $variants['a']['conversion_rate']]) }}
                                        @else
                                            {{ __('Variant B (Video) is winning with :rate% conversion rate.', ['rate' => $variants['b']['conversion_rate']]) }}
                                        @endif
                                    </span>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="px-6 py-8 text-center">
                            <flux:icon name="chart-bar" class="mx-auto size-8 text-zinc-300 dark:text-zinc-600" />
                            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ __('No views recorded for this offer in the selected date range.') }}
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
                        {{ __('Views and conversions by day across all offers.') }}
                    </flux:text>
                </div>

                <div class="max-h-96 overflow-y-auto overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800 sticky top-0">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('A Views') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('B Views') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('A Unique') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('B Unique') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('A Conv.') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('B Conv.') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach (array_reverse($trend) as $idx => $day)
                                <tr wire:key="day-{{ $idx }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $day['formatted'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">{{ $day['a_views'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">{{ $day['b_views'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">{{ $day['a_unique'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">{{ $day['b_unique'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $day['a_conversions'] > 0 ? 'font-medium text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $day['a_conversions'] }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $day['b_conversions'] > 0 ? 'font-medium text-green-600 dark:text-green-400' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $day['b_conversions'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
</div>
