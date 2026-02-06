<?php

use App\Models\CompanySetting;
use App\Models\ProductionPlanWeek;
use App\Models\SalesPlanGoal;
use App\Services\ProductionPlanService;
use App\Services\SalesPlanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $year;
    public int $selectedWeek;
    public bool $showWeekModal = false;
    public bool $showPlanModal = false;

    // Week form
    public int $editWeekNumber = 0;
    public float $revenuePlanned = 0;
    public int $jobsPlanned = 0;
    public float $hoursPlanned = 0;

    // Bulk plan form
    public float $weeklyRevenue = 28846; // ~$1.5M / 52 weeks
    public int $weeklyJobs = 6;
    public float $weeklyHours = 160;

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('view kpis')) {
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
    public function service(): ProductionPlanService
    {
        return new ProductionPlanService();
    }

    #[Computed]
    public function salesService(): SalesPlanService
    {
        return new SalesPlanService();
    }

    #[Computed]
    public function salesGoal(): SalesPlanGoal
    {
        return $this->salesService->getGoalForYear($this->settings, $this->year);
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
    public function annualProgress(): array
    {
        return $this->service->getAnnualProgress(
            $this->settings,
            $this->year,
            (float) $this->salesGoal->annual_revenue_goal
        );
    }

    #[Computed]
    public function currentWeek(): ProductionPlanWeek
    {
        return $this->service->getWeek($this->settings, $this->selectedWeek, $this->year);
    }

    public function changeYear(int $year): void
    {
        $this->year = $year;
        $this->selectedWeek = min($this->selectedWeek, 52);
        unset($this->salesGoal, $this->weeks, $this->ytdProgress, $this->annualProgress, $this->currentWeek);
    }

    public function selectWeek(int $week): void
    {
        $this->selectedWeek = $week;
        unset($this->currentWeek);
    }

    public function syncCurrentWeek(): void
    {
        abort_unless(auth()->user()->can('enter kpi actuals'), 403);

        $week = $this->service->getWeek($this->settings, $this->selectedWeek, $this->year);
        $this->service->syncWeekActuals($week);
        unset($this->currentWeek, $this->weeks, $this->ytdProgress, $this->annualProgress);
        session()->flash('success', 'Week actuals synced from completed jobs.');
    }

    public function syncAllWeeks(): void
    {
        abort_unless(auth()->user()->can('manage kpis'), 403);

        $this->service->syncYearActuals($this->settings, $this->year);
        unset($this->weeks, $this->ytdProgress, $this->annualProgress);
        session()->flash('success', 'All weeks synced from completed jobs.');
    }

    public function openWeekModal(int $week): void
    {
        $this->editWeekNumber = $week;
        $weekRecord = $this->service->getWeek($this->settings, $week, $this->year);
        $this->revenuePlanned = (float) $weekRecord->revenue_plan;
        $this->jobsPlanned = $weekRecord->jobs_completed_plan;
        $this->hoursPlanned = (float) $weekRecord->labor_hours_plan;
        $this->showWeekModal = true;
    }

    public function saveWeekPlan(): void
    {
        abort_unless(auth()->user()->can('edit kpi targets'), 403);

        $this->validate([
            'revenuePlanned' => 'required|numeric|min:0',
            'jobsPlanned' => 'required|integer|min:0',
            'hoursPlanned' => 'required|numeric|min:0',
        ]);

        $week = $this->service->getWeek($this->settings, $this->editWeekNumber, $this->year);
        $week->update([
            'revenue_plan' => $this->revenuePlanned,
            'jobs_completed_plan' => $this->jobsPlanned,
            'labor_hours_plan' => $this->hoursPlanned,
        ]);

        $this->showWeekModal = false;
        unset($this->weeks, $this->currentWeek, $this->ytdProgress, $this->annualProgress);
        session()->flash('success', 'Week plan updated.');
    }

    public function openPlanModal(): void
    {
        $annualGoal = (float) $this->salesGoal->annual_revenue_goal;
        $this->weeklyRevenue = round($annualGoal / 52, 2);
        $this->weeklyJobs = 6;
        $this->weeklyHours = 160;
        $this->showPlanModal = true;
    }

    public function populatePlan(): void
    {
        abort_unless(auth()->user()->can('manage kpis'), 403);

        $this->validate([
            'weeklyRevenue' => 'required|numeric|min:0',
            'weeklyJobs' => 'required|integer|min:0',
            'weeklyHours' => 'required|numeric|min:0',
        ]);

        $this->service->populatePlan(
            $this->settings,
            $this->year,
            $this->weeklyRevenue,
            $this->weeklyJobs,
            $this->weeklyHours
        );

        $this->showPlanModal = false;
        unset($this->weeks, $this->ytdProgress, $this->annualProgress);
        session()->flash('success', 'All weeks populated with production plan.');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="lg">Production Plan</flux:heading>
            <flux:subheading>Weekly production revenue and job completion tracking</flux:subheading>
        </div>
        <div class="flex items-center gap-3">
            <flux:select wire:model.live="year" class="w-32">
                @for ($y = now()->year - 2; $y <= now()->year + 1; $y++)
                    <option value="{{ $y }}">{{ $y }}</option>
                @endfor
            </flux:select>
            <flux:button icon="arrow-path" wire:click="syncAllWeeks" wire:loading.attr="disabled">
                Sync All
            </flux:button>
        </div>
    </div>

    @if (session('success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('success') }}
        </flux:callout>
    @endif

    {{-- Annual Progress Card --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-gradient-to-r from-blue-50 to-indigo-50 p-4 dark:border-zinc-700 dark:from-blue-900/20 dark:to-indigo-900/20">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="sm">{{ $this->year }} Annual Production Goal</flux:heading>
            <flux:button size="sm" icon="calculator" wire:click="openPlanModal">
                Set Weekly Targets
            </flux:button>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-6">
            <div>
                <flux:text class="text-sm text-zinc-500">Goal</flux:text>
                <flux:text class="text-lg font-semibold">${{ number_format($this->annualProgress['goal']) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Produced</flux:text>
                <flux:text class="text-lg font-semibold text-green-600">${{ number_format($this->annualProgress['actual']) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Progress</flux:text>
                <div class="flex items-center gap-2">
                    <flux:text class="text-lg font-semibold">{{ $this->annualProgress['percent'] }}%</flux:text>
                    <div class="h-2 w-16 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                        <div class="h-full bg-blue-600" style="width: {{ min(100, $this->annualProgress['percent']) }}%"></div>
                    </div>
                </div>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Remaining</flux:text>
                <flux:text class="text-lg font-semibold">${{ number_format($this->annualProgress['remaining']) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Weeks Left</flux:text>
                <flux:text class="text-lg font-semibold">{{ $this->annualProgress['weeks_remaining'] }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Required/Week</flux:text>
                <flux:text class="text-lg font-semibold {{ $this->annualProgress['required_weekly'] > $this->weeklyRevenue ? 'text-amber-600' : 'text-green-600' }}">
                    ${{ number_format($this->annualProgress['required_weekly']) }}
                </flux:text>
            </div>
        </div>
    </div>

    {{-- YTD Progress Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach (['revenue' => 'Revenue Produced', 'jobs' => 'Jobs Completed', 'labor_hours' => 'Labor Hours'] as $key => $label)
            @php
                $data = $this->ytdProgress[$key];
                $isGood = $data['percent'] >= 100;
            @endphp
            <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500">YTD {{ $label }}</flux:text>
                <div class="mt-1 flex items-baseline gap-2">
                    <flux:text class="text-2xl font-bold">
                        @if ($key === 'revenue')
                            ${{ number_format($data['actual']) }}
                        @elseif ($key === 'labor_hours')
                            {{ number_format($data['actual'], 1) }}
                        @else
                            {{ number_format($data['actual']) }}
                        @endif
                    </flux:text>
                    <flux:badge size="sm" color="{{ $isGood ? 'green' : 'amber' }}">
                        {{ $data['percent'] }}%
                    </flux:badge>
                </div>
                <flux:text class="text-xs text-zinc-400">
                    Plan:
                    @if ($key === 'revenue')
                        ${{ number_format($data['plan']) }}
                    @elseif ($key === 'labor_hours')
                        {{ number_format($data['plan'], 1) }}
                    @else
                        {{ number_format($data['plan']) }}
                    @endif
                </flux:text>
            </div>
        @endforeach
    </div>

    {{-- Weekly Table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">Weekly Breakdown</flux:heading>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Week</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Revenue</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Jobs Completed</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Labor Hours</th>
                        <th class="px-4 py-2 text-center font-medium">% of Plan</th>
                        <th class="px-4 py-2 text-center font-medium">Actions</th>
                    </tr>
                    <tr class="text-xs text-zinc-500">
                        <th></th>
                        <th class="px-2 py-1">Plan</th>
                        <th class="px-2 py-1">Actual</th>
                        <th class="px-2 py-1">Plan</th>
                        <th class="px-2 py-1">Actual</th>
                        <th class="px-2 py-1">Plan</th>
                        <th class="px-2 py-1">Actual</th>
                        <th></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->weeks as $week)
                        @php
                            $percentOfPlan = $week->revenue_percent_of_plan;
                        @endphp
                        <tr class="border-t border-zinc-100 dark:border-zinc-700 {{ $week->week_number === $this->selectedWeek ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                            <td class="px-4 py-2 font-medium">
                                <button wire:click="selectWeek({{ $week->week_number }})" class="hover:text-blue-600">
                                    Week {{ $week->week_number }}
                                </button>
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">${{ number_format($week->revenue_plan) }}</td>
                            <td class="px-2 py-2 text-center {{ $week->revenue_actual >= $week->revenue_plan ? 'text-green-600' : 'text-red-600' }}">
                                ${{ number_format($week->revenue_actual) }}
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">{{ $week->jobs_completed_plan }}</td>
                            <td class="px-2 py-2 text-center {{ $week->jobs_completed_actual >= $week->jobs_completed_plan ? 'text-green-600' : 'text-red-600' }}">
                                {{ $week->jobs_completed_actual }}
                            </td>
                            <td class="px-2 py-2 text-center text-zinc-500">{{ number_format($week->labor_hours_plan, 1) }}</td>
                            <td class="px-2 py-2 text-center {{ $week->labor_hours_actual >= $week->labor_hours_plan ? 'text-green-600' : 'text-red-600' }}">
                                {{ number_format($week->labor_hours_actual, 1) }}
                            </td>
                            <td class="px-2 py-2 text-center">
                                <flux:badge size="sm" color="{{ $percentOfPlan >= 100 ? 'green' : ($percentOfPlan >= 80 ? 'amber' : 'red') }}">
                                    {{ $percentOfPlan }}%
                                </flux:badge>
                            </td>
                            <td class="px-2 py-2 text-center">
                                <flux:button size="xs" variant="ghost" icon="pencil" wire:click="openWeekModal({{ $week->week_number }})" />
                            </td>
                        </tr>
                    @endforeach
                    @if ($this->weeks->isEmpty())
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-zinc-500">
                                No weeks created yet. Click "Set Weekly Targets" to create production targets.
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Week Plan Modal --}}
    <flux:modal wire:model="showWeekModal" name="week-modal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Edit Week {{ $editWeekNumber }} Plan</flux:heading>

            <flux:input
                type="number"
                label="Revenue Planned ($)"
                wire:model="revenuePlanned"
                step="100"
            />
            <flux:input
                type="number"
                label="Jobs Completed Planned"
                wire:model="jobsPlanned"
            />
            <flux:input
                type="number"
                label="Labor Hours Planned"
                wire:model="hoursPlanned"
                step="1"
            />

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showWeekModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveWeekPlan">Save Plan</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Bulk Plan Modal --}}
    <flux:modal wire:model="showPlanModal" name="plan-modal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Set {{ $this->year }} Weekly Targets</flux:heading>
            <flux:text class="text-sm text-zinc-500">
                These values will be applied to all 52 weeks of {{ $this->year }}.
            </flux:text>

            <flux:input
                type="number"
                label="Weekly Revenue Target ($)"
                wire:model="weeklyRevenue"
                step="100"
            />
            <flux:input
                type="number"
                label="Weekly Jobs Target"
                wire:model="weeklyJobs"
            />
            <flux:input
                type="number"
                label="Weekly Labor Hours Target"
                wire:model="weeklyHours"
                step="1"
            />

            <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    Annual totals: ${{ number_format($weeklyRevenue * 52) }} revenue,
                    {{ $weeklyJobs * 52 }} jobs, {{ number_format($weeklyHours * 52) }} hours
                </flux:text>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showPlanModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="populatePlan">Populate All Weeks</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
