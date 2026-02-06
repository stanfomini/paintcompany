<?php

use App\Models\CompanySetting;
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
    public float $goalAnnualRevenue = 1500000;
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
    public function service(): SalesPlanService
    {
        return new SalesPlanService();
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

    public function changeYear(int $year): void
    {
        $this->year = $year;
        $this->selectedWeek = min($this->selectedWeek, 52);
        unset($this->goal, $this->weeks, $this->ytdProgress, $this->currentWeek);
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
        unset($this->currentWeek, $this->weeks, $this->ytdProgress);
        session()->flash('success', 'Week actuals synced from app data.');
    }

    public function syncAllWeeks(): void
    {
        abort_unless(auth()->user()->can('manage kpis'), 403);

        $this->service->syncYearActuals($this->settings, $this->year);
        unset($this->weeks, $this->ytdProgress);
        session()->flash('success', 'All weeks synced from app data.');
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
        abort_unless(auth()->user()->can('edit kpi targets'), 403);

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
        unset($this->goal, $this->ytdProgress);
        session()->flash('success', 'Sales goals updated.');
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
        abort_unless(auth()->user()->can('edit kpi targets'), 403);

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
        session()->flash('success', 'Week plan updated.');
    }

    public function populateFromGoals(): void
    {
        abort_unless(auth()->user()->can('manage kpis'), 403);

        $this->service->populatePlanFromGoals($this->settings, $this->year);
        unset($this->weeks);
        session()->flash('success', 'All weeks populated from annual goals.');
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <flux:heading size="lg">Sales Plan</flux:heading>
            <flux:subheading>Weekly sales targets and actuals tracking</flux:subheading>
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

    {{-- Annual Goals Card --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="sm">{{ $this->year }} Goals</flux:heading>
            <div class="flex gap-2">
                <flux:button size="sm" variant="ghost" wire:click="populateFromGoals">
                    Populate Weeks
                </flux:button>
                <flux:button size="sm" icon="pencil" wire:click="openGoalModal">
                    Edit
                </flux:button>
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <flux:text class="text-sm text-zinc-500">Annual Revenue Goal</flux:text>
                <flux:text class="text-lg font-semibold">${{ number_format($this->goal->annual_revenue_goal) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Avg Job Size</flux:text>
                <flux:text class="text-lg font-semibold">${{ number_format($this->goal->avg_job_size) }}</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Lead Conversion</flux:text>
                <flux:text class="text-lg font-semibold">{{ $this->goal->lead_conversion_rate }}%</flux:text>
            </div>
            <div>
                <flux:text class="text-sm text-zinc-500">Close Rate</flux:text>
                <flux:text class="text-lg font-semibold">{{ $this->goal->estimate_close_rate }}%</flux:text>
            </div>
        </div>
    </div>

    {{-- YTD Progress Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach (['leads' => 'Leads', 'estimates' => 'Estimates', 'jobs_booked' => 'Jobs Booked', 'revenue' => 'Revenue'] as $key => $label)
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
                        @else
                            {{ number_format($data['actual']) }}
                        @endif
                    </flux:text>
                    <flux:badge size="sm" color="{{ $isGood ? 'green' : 'amber' }}">
                        {{ $data['percent'] }}%
                    </flux:badge>
                </div>
                <flux:text class="text-xs text-zinc-400">
                    Target:
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
            <flux:heading size="sm">Weekly Breakdown</flux:heading>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Week</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Leads</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Estimates</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Jobs Booked</th>
                        <th class="px-4 py-2 text-center font-medium" colspan="2">Revenue</th>
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
                        <th class="px-2 py-1">Plan</th>
                        <th class="px-2 py-1">Actual</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($this->weeks as $week)
                        <tr class="border-t border-zinc-100 dark:border-zinc-700 {{ $week->week_number === $this->selectedWeek ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                            <td class="px-4 py-2 font-medium">
                                <button wire:click="selectWeek({{ $week->week_number }})" class="hover:text-blue-600">
                                    Week {{ $week->week_number }}
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
                                No weeks created yet. Click "Populate Weeks" to create weekly targets from your goals.
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
            <flux:heading size="lg">Edit {{ $this->year }} Goals</flux:heading>

            <flux:input
                type="number"
                label="Annual Revenue Goal ($)"
                wire:model="goalAnnualRevenue"
                step="1000"
            />
            <flux:input
                type="number"
                label="Average Job Size ($)"
                wire:model="goalAvgJobSize"
                step="100"
            />
            <flux:input
                type="number"
                label="Lead Conversion Rate (%)"
                wire:model="goalLeadConversion"
                step="1"
                max="100"
            />
            <flux:input
                type="number"
                label="Estimate Close Rate (%)"
                wire:model="goalCloseRate"
                step="1"
                max="100"
            />

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showGoalModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveGoal">Save Goals</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Week Plan Modal --}}
    <flux:modal wire:model="showWeekModal" name="week-modal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Edit Week {{ $editWeekNumber }} Plan</flux:heading>

            <flux:input
                type="number"
                label="Leads Planned"
                wire:model="leadsPlanned"
            />
            <flux:input
                type="number"
                label="Estimates Planned"
                wire:model="estimatesPlanned"
            />
            <flux:input
                type="number"
                label="Jobs Booked Planned"
                wire:model="jobsPlanned"
            />
            <flux:input
                type="number"
                label="Revenue Planned ($)"
                wire:model="revenuePlanned"
                step="100"
            />

            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showWeekModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="saveWeekPlan">Save Plan</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
