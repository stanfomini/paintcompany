<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\ProductionPlanWeek;
use App\Models\SalesPlanGoal;
use App\Models\SalesPlanWeek;
use App\Services\SalesPlanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $year;

    public int $selectedWeek;

    public bool $showGoalModal = false;

    public bool $showWeekModal = false;

    // Goal form
    public float $goalAnnualRevenue = 0;

    public float $goalAvgJobSize = 5000;

    public float $goalLeadConversion = 65;

    public float $goalCloseRate = 55;

    // Week form
    public int $editWeekNumber = 0;

    public int $leadsPlanned = 0;

    public int $estimatesPlanned = 0;

    public int $jobsPlanned = 0;

    public float $revenuePlanned = 0;

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('kpis.view')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->year = now()->year;
        $this->selectedWeek = now()->weekOfYear;
    }

    #[Computed]
    public function settings(): CompanySetting
    {
        return CompanySetting::getInstance();
    }

    #[Computed]
    public function service(): SalesPlanService
    {
        return new SalesPlanService;
    }

    #[Computed]
    public function goal(): SalesPlanGoal
    {
        return $this->service->getGoalForYear($this->settings, $this->year);
    }

    #[Computed]
    public function weeks(): \Illuminate\Support\Collection
    {
        return $this->service->getWeeksForYear($this->settings, $this->year);
    }

    #[Computed]
    public function ytdProgress(): array
    {
        return $this->service->getYTDProgress($this->settings, $this->year);
    }

    #[Computed]
    public function currentWeek(): SalesPlanWeek
    {
        return $this->service->getWeek($this->settings, $this->selectedWeek, $this->year);
    }

    /**
     * Project the P&L if the revenue goal is hit, using actual allocation percentages.
     *
     * @return array{revenue: float, cogs: float, gross_profit: float, overhead_items: array, overhead_total: float, net_profit: float, net_margin: float, tax_reserve: float, owner_take_home: float}
     */
    #[Computed]
    public function projectedPL(): array
    {
        $goal = $this->goal;
        $revenue = (float) $goal->annual_revenue_goal;

        if ($revenue <= 0) {
            return [
                'revenue' => 0, 'cogs' => 0, 'gross_profit' => 0,
                'overhead_items' => [], 'overhead_total' => 0,
                'net_profit' => 0, 'net_margin' => 0,
                'tax_reserve' => 0, 'owner_take_home' => 0,
            ];
        }

        $settings = $this->settings;

        // COGS: labor hours from production plan × effective hourly rate
        $totalLaborHours = (float) ProductionPlanWeek::where('company_setting_id', $settings->id)
            ->where('year', $this->year)
            ->sum('labor_hours_plan');

        $effectiveRate = (float) $settings->interior_hourly_wage * (float) $settings->payroll_burden_multiplier;
        $cogs = round($totalLaborHours * $effectiveRate, 2);
        $grossProfit = $revenue - $cogs;

        // Overhead from allocations
        $totalJobs = $goal->required_jobs;
        $allocations = CompanyAllocation::where('company_setting_id', $settings->id)
            ->active()
            ->reducesProfit()
            ->ordered()
            ->get();

        $overheadItems = [];
        $overheadTotal = 0;

        foreach ($allocations as $allocation) {
            $amount = $allocation->calculateAmount($revenue, $totalLaborHours);
            $overheadItems[] = [
                'name' => $allocation->name,
                'amount' => $amount,
                'formatted' => $allocation->formatted_value,
            ];
            $overheadTotal += $amount;
        }

        $netProfit = $grossProfit - $overheadTotal;
        $netMargin = $revenue > 0 ? round(($netProfit / $revenue) * 100, 1) : 0;

        $taxReservePercent = (float) ($settings->tax_reserve_percent ?? 30);
        $taxReserve = round(max(0, $netProfit) * ($taxReservePercent / 100), 2);
        $ownerTakeHome = $netProfit - $taxReserve;

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $revenue > 0 ? round(($grossProfit / $revenue) * 100, 1) : 0,
            'overhead_items' => $overheadItems,
            'overhead_total' => round($overheadTotal, 2),
            'net_profit' => round($netProfit, 2),
            'net_margin' => $netMargin,
            'tax_reserve_percent' => $taxReservePercent,
            'tax_reserve' => $taxReserve,
            'owner_take_home' => round($ownerTakeHome, 2),
        ];
    }

    public function changeYear(int $year): void
    {
        $this->year = $year;
        $this->selectedWeek = min($this->selectedWeek, 52);
        unset($this->goal, $this->weeks, $this->ytdProgress, $this->currentWeek, $this->projectedPL);
    }

    public function selectWeek(int $week): void
    {
        $this->selectedWeek = $week;
        unset($this->currentWeek);
    }

    public function syncCurrentWeek(): void
    {
        abort_unless(auth()->user()->can('kpis.enter_actuals'), 403);

        $week = $this->service->getWeek($this->settings, $this->selectedWeek, $this->year);
        $this->service->syncWeekActuals($week);
        unset($this->currentWeek, $this->weeks, $this->ytdProgress);
        session()->flash('success', __('Week actuals synced from app data.'));
    }

    public function syncAllWeeks(): void
    {
        abort_unless(auth()->user()->can('kpis.manage'), 403);

        $this->service->syncYearActuals($this->settings, $this->year);
        unset($this->weeks, $this->ytdProgress);
        session()->flash('success', __('All weeks synced from app data.'));
    }

    public function openGoalModal(): void
    {
        $goal = $this->goal;
        $this->goalAnnualRevenue = (float) $goal->annual_revenue_goal;
        $this->goalAvgJobSize = (float) $goal->avg_job_size;
        $this->goalLeadConversion = (float) $goal->lead_conversion_rate;
        $this->goalCloseRate = (float) $goal->estimate_close_rate;
        $this->showGoalModal = true;
    }

    public function saveGoal(): void
    {
        abort_unless(auth()->user()->can('kpis.edit_targets'), 403);

        $this->validate([
            'goalAnnualRevenue' => 'required|numeric|min:0',
            'goalAvgJobSize' => 'required|numeric|min:0',
            'goalLeadConversion' => 'required|numeric|min:0|max:100',
            'goalCloseRate' => 'required|numeric|min:0|max:100',
        ]);

        $goal = $this->goal;
        $goal->update([
            'annual_revenue_goal' => $this->goalAnnualRevenue,
            'avg_job_size' => $this->goalAvgJobSize,
            'lead_conversion_rate' => $this->goalLeadConversion,
            'estimate_close_rate' => $this->goalCloseRate,
        ]);

        $this->showGoalModal = false;
        unset($this->goal, $this->ytdProgress, $this->projectedPL);
        session()->flash('success', __('Sales goals updated.'));
    }

    public function openWeekModal(int $week): void
    {
        $this->editWeekNumber = $week;
        $weekRecord = $this->service->getWeek($this->settings, $week, $this->year);
        $this->leadsPlanned = $weekRecord->leads_plan;
        $this->estimatesPlanned = $weekRecord->estimates_plan;
        $this->jobsPlanned = $weekRecord->jobs_booked_plan;
        $this->revenuePlanned = (float) $weekRecord->revenue_plan;
        $this->showWeekModal = true;
    }

    public function saveWeekPlan(): void
    {
        abort_unless(auth()->user()->can('kpis.edit_targets'), 403);

        $this->validate([
            'leadsPlanned' => 'required|integer|min:0',
            'estimatesPlanned' => 'required|integer|min:0',
            'jobsPlanned' => 'required|integer|min:0',
            'revenuePlanned' => 'required|numeric|min:0',
        ]);

        $week = $this->service->getWeek($this->settings, $this->editWeekNumber, $this->year);
        $week->update([
            'leads_plan' => $this->leadsPlanned,
            'estimates_plan' => $this->estimatesPlanned,
            'jobs_booked_plan' => $this->jobsPlanned,
            'revenue_plan' => $this->revenuePlanned,
        ]);

        $this->showWeekModal = false;
        unset($this->weeks, $this->currentWeek);
        session()->flash('success', __('Week plan updated.'));
    }

    public function populateFromGoals(): void
    {
        abort_unless(auth()->user()->can('kpis.manage'), 403);

        $this->service->populatePlanFromGoals($this->settings, $this->year);
        unset($this->weeks);
        session()->flash('success', __('All weeks populated from annual goals.'));
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="lg">{{ __('Sales Plan') }}</flux:heading>
            <flux:subheading>{{ __('Weekly sales targets and actuals tracking') }}</flux:subheading>
        </div>
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="year" class="w-32">
                @for ($y = now()->year - 2; $y <= now()->year + 1; $y++)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endfor
            </flux:select>
            <flux:button icon="arrow-path" wire:click="syncAllWeeks" wire:loading.attr="disabled">
                {{ __('Sync All') }}
            </flux:button>
        </div>
    </div>

    @if (session('success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('success') }}
        </flux:callout>
    @endif

    {{-- Projected P&L Waterfall --}}
    @php $pl = $this->projectedPL; @endphp
    @if ($pl['revenue'] > 0)
        <div class="mb-6 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-5 py-3 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('If You Hit') }} ${{ number_format($pl['revenue']) }}</flux:heading>
            </div>
            <div class="px-5 py-4">
                {{-- Waterfall bar --}}
                @php
                    $barSegments = [
                        ['label' => __('COGS'), 'value' => $pl['cogs'], 'color' => 'bg-red-400 dark:bg-red-500'],
                        ['label' => __('Overhead'), 'value' => $pl['overhead_total'], 'color' => 'bg-amber-400 dark:bg-amber-500'],
                        ['label' => __('Taxes'), 'value' => $pl['tax_reserve'], 'color' => 'bg-zinc-400 dark:bg-zinc-500'],
                        ['label' => __('Take-Home'), 'value' => max(0, $pl['owner_take_home']), 'color' => 'bg-green-500 dark:bg-green-400'],
                    ];
                @endphp
                <div class="mb-4 flex h-8 w-full overflow-hidden rounded-lg">
                    @foreach ($barSegments as $seg)
                        @if ($seg['value'] > 0)
                            <div
                                class="{{ $seg['color'] }}"
                                style="width: {{ round(($seg['value'] / $pl['revenue']) * 100, 1) }}%"
                                title="{{ $seg['label'] }}: ${{ number_format($seg['value']) }}"
                            ></div>
                        @endif
                    @endforeach
                </div>

                {{-- Legend --}}
                <div class="mb-4 flex flex-wrap gap-x-5 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                    @foreach ($barSegments as $seg)
                        <span class="flex items-center gap-1.5">
                            <span class="{{ $seg['color'] }} inline-block h-2.5 w-2.5 rounded-sm"></span>
                            {{ $seg['label'] }}
                            ({{ round(($seg['value'] / $pl['revenue']) * 100) }}%)
                        </span>
                    @endforeach
                </div>

                {{-- P&L Summary --}}
                <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                    <div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ __('Revenue') }}</div>
                        <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($pl['revenue']) }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ __('COGS (Labor)') }}</div>
                        <div class="text-lg font-bold text-red-600 dark:text-red-400">-${{ number_format($pl['cogs']) }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ __('Gross Profit') }}</div>
                        <div class="text-lg font-bold {{ $pl['gross_profit'] >= 0 ? 'text-zinc-900 dark:text-zinc-100' : 'text-red-600' }}">
                            ${{ number_format($pl['gross_profit']) }}
                            <span class="text-xs font-normal text-zinc-400">({{ $pl['gross_margin'] }}%)</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-zinc-500 dark:text-zinc-400">{{ __('Overhead') }}</div>
                        <div class="text-lg font-bold text-amber-600 dark:text-amber-400">-${{ number_format($pl['overhead_total']) }}</div>
                    </div>
                </div>

                <div class="mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                    <div class="grid grid-cols-2 gap-x-8 gap-y-2 text-sm sm:grid-cols-4">
                        <div>
                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Net Profit') }}</div>
                            <div class="text-lg font-bold {{ $pl['net_profit'] >= 0 ? 'text-blue-600 dark:text-blue-400' : 'text-red-600' }}">
                                ${{ number_format($pl['net_profit']) }}
                                <span class="text-xs font-normal text-zinc-400">({{ $pl['net_margin'] }}%)</span>
                            </div>
                        </div>
                        <div>
                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Tax Reserve') }} ({{ number_format($pl['tax_reserve_percent']) }}%)</div>
                            <div class="text-lg font-bold text-zinc-500 dark:text-zinc-400">-${{ number_format($pl['tax_reserve']) }}</div>
                        </div>
                        <div class="sm:col-span-2">
                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Owner Take-Home') }}</div>
                            <div class="text-2xl font-bold {{ $pl['owner_take_home'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600' }}">
                                ${{ number_format($pl['owner_take_home']) }}
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Overhead breakdown toggle --}}
                <details class="mt-3">
                    <summary class="cursor-pointer text-xs text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300">
                        {{ __('Overhead breakdown') }}
                    </summary>
                    <div class="mt-2 space-y-1 text-xs">
                        @foreach ($pl['overhead_items'] as $item)
                            <div class="flex justify-between text-zinc-500 dark:text-zinc-400">
                                <span>{{ $item['name'] }} ({{ $item['formatted'] }})</span>
                                <span>${{ number_format($item['amount']) }}</span>
                            </div>
                        @endforeach
                    </div>
                </details>
            </div>
        </div>
    @endif

    {{-- Annual Goals Card --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="sm">{{ __(':year Goals', ['year' => $this->year]) }}</flux:heading>
            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="populateFromGoals">
                    {{ __('Populate Weeks') }}
                </flux:button>
                <flux:button size="sm" icon="pencil" wire:click="openGoalModal">
                    {{ __('Edit') }}
                </flux:button>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Annual Revenue Goal') }}</flux:text>
                <flux:text class="text-lg font-semibold">${{ number_format($this->goal->annual_revenue_goal) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Avg Job Size') }}</flux:text>
                <flux:text class="text-lg font-semibold">${{ number_format($this->goal->avg_job_size) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Lead Conversion') }}</flux:text>
                <flux:text class="text-lg font-semibold">{{ $this->goal->lead_conversion_rate }}%</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">{{ __('Close Rate') }}</flux:text>
                <flux:text class="text-lg font-semibold">{{ $this->goal->estimate_close_rate }}%</flux:text>
            </div>
        </div>
    </div>

    {{-- YTD Progress Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach (['leads' => __('Leads'), 'estimates' => __('Estimates'), 'jobs_booked' => __('Jobs Booked'), 'revenue' => __('Revenue')] as $key => $label)
            @php
                $data = $this->ytdProgress[$key];
                $isGood = $data['percent'] >= 100;
            @endphp
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500">{{ __('YTD') }} {{ $label }}</flux:text>
                <div class="mt-1 flex items-baseline gap-2">
                    <flux:text class="text-2xl font-bold">
                        @if ($key === 'revenue')
                            ${{ number_format($data['actual']) }}
                        @else
                            {{ number_format($data['actual']) }}
                        @endif
                    </flux:text>
                    <flux:badge size="sm" color="{{ $isGood ? 'green' : 'amber' }}">
                        {{ $data['percent'] }}%
                    </flux:badge>
                </div>
                <flux:text class="text-xs text-zinc-400">
                    {{ __('Target:') }}
                    @if ($key === 'revenue')
                        ${{ number_format($data['target']) }}
                    @else
                        {{ number_format($data['target']) }}
                    @endif
                </flux:text>
            </div>
        @endforeach
    </div>

    {{-- Weekly Table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Weekly Breakdown') }}</flux:heading>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">{{ __('Week') }}</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">{{ __('Leads') }}</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">{{ __('Estimates') }}</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">{{ __('Jobs Booked') }}</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">{{ __('Revenue') }}</th>
                        <th class="px-4 py-2 text-center font-medium">{{ __('Actions') }}</th>
                    </tr>
                    <tr class="text-xs text-zinc-500">
                        <th></th>
                        <th class="px-2 py-1">{{ __('Plan') }}</th>
                        <th class="px-2 py-1">{{ __('Actual') }}</th>
                        <th class="px-2 py-1">{{ __('Plan') }}</th>
                        <th class="px-2 py-1">{{ __('Actual') }}</th>
                        <th class="px-2 py-1">{{ __('Plan') }}</th>
                        <th class="px-2 py-1">{{ __('Actual') }}</th>
                        <th class="px-2 py-1">{{ __('Plan') }}</th>
                        <th class="px-2 py-1">{{ __('Actual') }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->weeks as $week)
                        <tr class="border-t border-zinc-100 dark:border-zinc-700 {{ $week->week_number === $this->selectedWeek ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                            <td class="px-4 py-2 font-medium">
                                <button wire:click="selectWeek({{ $week->week_number }})" class="hover:text-blue-600">
                                    {{ __('Week') }} {{ $week->week_number }}
                                </button>
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">{{ $week->leads_plan }}</td>
                            <td class="px-2 py-2 text-center {{ $week->leads_actual >= $week->leads_plan ? 'text-green-600' : 'text-red-600' }}">
                                {{ $week->leads_actual }}
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">{{ $week->estimates_plan }}</td>
                            <td class="px-2 py-2 text-center {{ $week->estimates_actual >= $week->estimates_plan ? 'text-green-600' : 'text-red-600' }}">
                                {{ $week->estimates_actual }}
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">{{ $week->jobs_booked_plan }}</td>
                            <td class="px-2 py-2 text-center {{ $week->jobs_booked_actual >= $week->jobs_booked_plan ? 'text-green-600' : 'text-red-600' }}">
                                {{ $week->jobs_booked_actual }}
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">${{ number_format($week->revenue_plan) }}</td>
                            <td class="px-2 py-2 text-center {{ $week->revenue_actual >= $week->revenue_plan ? 'text-green-600' : 'text-red-600' }}">
                                ${{ number_format($week->revenue_actual) }}
                            </td>
                            <td class="px-2 py-2 text-center">
                                <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openWeekModal({{ $week->week_number }})" />
                            </td>
                        </tr>
                    @endforeach
                    @if ($this->weeks->isEmpty())
                        <tr>
                            <td colspan="10" class="px-4 py-8 text-center text-zinc-500">
                                {{ __('No weeks created yet. Click "Populate Weeks" to create weekly targets from your goals.') }}
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Goal Modal --}}
    <flux:modal wire:model="showGoalModal" name="goal-modal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Edit :year Goals', ['year' => $this->year]) }}</flux:heading>

            <flux:input
                type="number"
                :label="__('Annual Revenue Goal ($)')"
                wire:model="goalAnnualRevenue"
                step="1000"
            />
            <flux:input
                type="number"
                :label="__('Average Job Size ($)')"
                wire:model="goalAvgJobSize"
                step="100"
            />
            <flux:input
                type="number"
                :label="__('Lead Conversion Rate (%)')"
                wire:model="goalLeadConversion"
                step="1"
                max="100"
            />
            <flux:input
                type="number"
                :label="__('Estimate Close Rate (%)')"
                wire:model="goalCloseRate"
                step="1"
                max="100"
            />

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showGoalModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveGoal">{{ __('Save Goals') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Week Plan Modal --}}
    <flux:modal wire:model="showWeekModal" name="week-modal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Edit Week :week Plan', ['week' => $editWeekNumber]) }}</flux:heading>

            <flux:input
                type="number"
                :label="__('Leads Planned')"
                wire:model="leadsPlanned"
            />
            <flux:input
                type="number"
                :label="__('Estimates Planned')"
                wire:model="estimatesPlanned"
            />
            <flux:input
                type="number"
                :label="__('Jobs Booked Planned')"
                wire:model="jobsPlanned"
            />
            <flux:input
                type="number"
                :label="__('Revenue Planned ($)')"
                wire:model="revenuePlanned"
                step="100"
            />

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showWeekModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="saveWeekPlan">{{ __('Save Plan') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
