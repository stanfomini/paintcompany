<?php

use App\Models\CompensationScenario;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // Global inputs
    public float $avgJobSize = 10000;
    public float $knockToApptRate = 10;
    public float $apptToSoldRate = 40;
    public int $workDaysPerWeek = 5;
    public int $workWeeksPerYear = 50;
    public int $knocksPerDay = 30;

    // Commission Only model
    public float $knockerCommissionPct = 4;
    public float $estimatorSoloPct = 10;
    public float $estimatorTeamPct = 6;
    public float $regionManagerPct = 2;

    // Flat + Commission model
    public float $flatPerEstimate = 50;
    public float $flatPlusCommissionPct = 1;
    public float $flatEstimatorPct = 6;
    public float $flatRegionPct = 2;

    // Flat Only model
    public float $flatOnlyPerEstimate = 125;
    public float $flatOnlyEstimatorPct = 6;
    public float $flatOnlyRegionPct = 2;

    // Target income (reverse calc)
    public float $targetIncome = 80000;

    // Scenario management
    public string $scenarioName = '';

    public function mount(): void
    {
        if (! auth()->user()->hasRole(['owner', 'admin'])) {
            abort(403);
        }

        $defaults = \App\Models\CompanySetting::getInstance()->commission_defaults ?? [];
        $knockerRules = $defaults['knocker'] ?? [];
        $estimatorRules = $defaults['estimator'] ?? [];
        $managerRules = $defaults['manager_override'] ?? [];

        foreach ($knockerRules as $rule) {
            if (($rule['source'] ?? '') === 'job' && ($rule['type'] ?? '') === 'revenue_percentage') {
                $this->knockerCommissionPct = (($rule['rate'] ?? 0) * 100);
            }
        }
        foreach ($estimatorRules as $rule) {
            if (($rule['source'] ?? '') === 'estimate' && ($rule['type'] ?? '') === 'revenue_percentage') {
                $this->estimatorTeamPct = (($rule['rate'] ?? 0) * 100);
            }
        }
        foreach ($managerRules as $rule) {
            if (($rule['source'] ?? '') === 'territory_override' && ($rule['type'] ?? '') === 'revenue_percentage') {
                $this->regionManagerPct = (($rule['rate'] ?? 0) * 100);
            }
        }
    }

    public function saveScenario(): void
    {
        $this->validate(['scenarioName' => 'required|string|max:255']);

        CompensationScenario::create([
            'name' => $this->scenarioName,
            'created_by' => auth()->id(),
            'settings' => $this->getAllSettings(),
        ]);

        $this->scenarioName = '';
        unset($this->scenarios);
    }

    public function loadScenario(int $id): void
    {
        $scenario = CompensationScenario::findOrFail($id);
        $settings = $scenario->settings;

        foreach ($settings as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }

    #[Computed]
    public function scenarios(): \Illuminate\Support\Collection
    {
        return CompensationScenario::where('created_by', auth()->id())
            ->orderByDesc('updated_at')
            ->get();
    }

    private function getAllSettings(): array
    {
        return [
            'avgJobSize' => $this->avgJobSize,
            'knockToApptRate' => $this->knockToApptRate,
            'apptToSoldRate' => $this->apptToSoldRate,
            'workDaysPerWeek' => $this->workDaysPerWeek,
            'workWeeksPerYear' => $this->workWeeksPerYear,
            'knocksPerDay' => $this->knocksPerDay,
            'knockerCommissionPct' => $this->knockerCommissionPct,
            'estimatorSoloPct' => $this->estimatorSoloPct,
            'estimatorTeamPct' => $this->estimatorTeamPct,
            'regionManagerPct' => $this->regionManagerPct,
            'flatPerEstimate' => $this->flatPerEstimate,
            'flatPlusCommissionPct' => $this->flatPlusCommissionPct,
            'flatEstimatorPct' => $this->flatEstimatorPct,
            'flatRegionPct' => $this->flatRegionPct,
            'flatOnlyPerEstimate' => $this->flatOnlyPerEstimate,
            'flatOnlyEstimatorPct' => $this->flatOnlyEstimatorPct,
            'flatOnlyRegionPct' => $this->flatOnlyRegionPct,
            'targetIncome' => $this->targetIncome,
        ];
    }
};
?>

<div x-data="compensationPlanner()" class="space-y-6">
    <flux:heading size="xl">Compensation Planner</flux:heading>
    <p class="text-sm text-zinc-500 dark:text-zinc-400">Compare three door knocker compensation models. Adjust sliders to see projected annual income and required activity levels.</p>

    <flux:callout variant="info" class="mb-4">
        Starting values are loaded from your <a href="{{ route('commissions.edit') }}" class="underline font-medium" wire:navigate>Commission Settings</a>.
        Changes here are for modeling only — they don't update actual commission rates.
    </flux:callout>

    {{-- Global Inputs --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Global Settings</flux:heading>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Avg Job Size: $<span x-text="formatNumber($wire.avgJobSize)"></span></label>
                <input type="range" wire:model.live.debounce.300ms="avgJobSize" min="1000" max="25000" step="500" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Knock-to-Appt Rate: <span x-text="$wire.knockToApptRate"></span>%</label>
                <input type="range" wire:model.live.debounce.300ms="knockToApptRate" min="5" max="30" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Appt-to-Sold Rate: <span x-text="$wire.apptToSoldRate"></span>%</label>
                <input type="range" wire:model.live.debounce.300ms="apptToSoldRate" min="20" max="60" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Knocks/Day: <span x-text="$wire.knocksPerDay"></span></label>
                <input type="range" wire:model.live.debounce.300ms="knocksPerDay" min="10" max="60" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Work Days/Week: <span x-text="$wire.workDaysPerWeek"></span></label>
                <input type="range" wire:model.live.debounce.300ms="workDaysPerWeek" min="4" max="6" step="1" class="w-full">
            </div>
            <div>
                <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Work Weeks/Year: <span x-text="$wire.workWeeksPerYear"></span></label>
                <input type="range" wire:model.live.debounce.300ms="workWeeksPerYear" min="48" max="52" step="1" class="w-full">
            </div>
        </div>
    </div>

    {{-- 3-Column Comparison --}}
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- Commission Only --}}
        <div class="rounded-lg border border-blue-200 dark:border-blue-800 p-4 space-y-4">
            <flux:heading size="sm">Commission Only</flux:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-zinc-500">Knocker: <span x-text="$wire.knockerCommissionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="knockerCommissionPct" min="1" max="8" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator (solo): <span x-text="$wire.estimatorSoloPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="estimatorSoloPct" min="4" max="15" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator (team): <span x-text="$wire.estimatorTeamPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="estimatorTeamPct" min="2" max="10" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Region Mgr Override: <span x-text="$wire.regionManagerPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="regionManagerPct" min="0.5" max="5" step="0.25" class="w-full">
                </div>
            </div>
            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">Knocker Annual</span><span class="font-semibold text-green-600" x-text="'$' + formatNumber(calcKnockerIncome('commission'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Estimator Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcEstimatorIncome('commission'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Region Mgr Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcManagerIncome('commission'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Total % of Revenue</span>
                    <span class="font-semibold" :class="calcTotalPct('commission') <= 12 ? 'text-green-600' : 'text-red-600'" x-text="calcTotalPct('commission').toFixed(1) + '%'"></span>
                </div>
            </div>
        </div>

        {{-- Flat + Commission --}}
        <div class="rounded-lg border border-purple-200 dark:border-purple-800 p-4 space-y-4">
            <flux:heading size="sm">Flat + Commission</flux:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-zinc-500">Flat $/Estimate: $<span x-text="$wire.flatPerEstimate"></span></label>
                    <input type="range" wire:model.live.debounce.300ms="flatPerEstimate" min="25" max="100" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">+ Commission: <span x-text="$wire.flatPlusCommissionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatPlusCommissionPct" min="0.5" max="4" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator: <span x-text="$wire.flatEstimatorPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatEstimatorPct" min="2" max="10" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Region Mgr Override: <span x-text="$wire.flatRegionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatRegionPct" min="0.5" max="5" step="0.25" class="w-full">
                </div>
            </div>
            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">Knocker Annual</span><span class="font-semibold text-green-600" x-text="'$' + formatNumber(calcKnockerIncome('flat_plus'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Estimator Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcEstimatorIncome('flat_plus'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Region Mgr Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcManagerIncome('flat_plus'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Total % of Revenue</span>
                    <span class="font-semibold" :class="calcTotalPct('flat_plus') <= 12 ? 'text-green-600' : 'text-red-600'" x-text="calcTotalPct('flat_plus').toFixed(1) + '%'"></span>
                </div>
            </div>
        </div>

        {{-- Flat Only --}}
        <div class="rounded-lg border border-amber-200 dark:border-amber-800 p-4 space-y-4">
            <flux:heading size="sm">Flat Only</flux:heading>
            <div class="space-y-3">
                <div>
                    <label class="text-xs text-zinc-500">Flat $/Estimate: $<span x-text="$wire.flatOnlyPerEstimate"></span></label>
                    <input type="range" wire:model.live.debounce.300ms="flatOnlyPerEstimate" min="75" max="200" step="5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Estimator: <span x-text="$wire.flatOnlyEstimatorPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatOnlyEstimatorPct" min="2" max="10" step="0.5" class="w-full">
                </div>
                <div>
                    <label class="text-xs text-zinc-500">Region Mgr Override: <span x-text="$wire.flatOnlyRegionPct"></span>%</label>
                    <input type="range" wire:model.live.debounce.300ms="flatOnlyRegionPct" min="0.5" max="5" step="0.25" class="w-full">
                </div>
            </div>
            <div class="border-t border-zinc-200 dark:border-zinc-700 pt-3 space-y-2 text-sm">
                <div class="flex justify-between"><span class="text-zinc-500">Knocker Annual</span><span class="font-semibold text-green-600" x-text="'$' + formatNumber(calcKnockerIncome('flat_only'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Estimator Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcEstimatorIncome('flat_only'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Region Mgr Annual</span><span class="font-semibold" x-text="'$' + formatNumber(calcManagerIncome('flat_only'))"></span></div>
                <div class="flex justify-between"><span class="text-zinc-500">Total % of Revenue</span>
                    <span class="font-semibold" :class="calcTotalPct('flat_only') <= 12 ? 'text-green-600' : 'text-red-600'" x-text="calcTotalPct('flat_only').toFixed(1) + '%'"></span>
                </div>
            </div>
        </div>
    </div>

    {{-- Reverse Calculator --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Reverse Calculator: Target Income</flux:heading>
        <div>
            <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Target Annual Income: $<span x-text="formatNumber($wire.targetIncome)"></span></label>
            <input type="range" wire:model.live.debounce.300ms="targetIncome" min="40000" max="150000" step="5000" class="w-full">
        </div>
        <div class="grid gap-4 sm:grid-cols-3 text-sm">
            <template x-for="model in ['commission', 'flat_plus', 'flat_only']" :key="model">
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg space-y-1">
                    <p class="font-medium" x-text="modelLabel(model)"></p>
                    <p class="text-zinc-500">Knocks/day needed: <span class="font-semibold text-zinc-900 dark:text-zinc-100" x-text="calcRequiredKnocksPerDay(model)"></span></p>
                    <p class="text-zinc-500">Appts/week: <span class="font-semibold" x-text="calcRequiredApptsPerWeek(model)"></span></p>
                    <p class="text-zinc-500">Jobs/month: <span class="font-semibold" x-text="calcRequiredJobsPerMonth(model)"></span></p>
                </div>
            </template>
        </div>
    </div>

    {{-- Scenario Management --}}
    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4 space-y-4">
        <flux:heading size="sm">Saved Scenarios</flux:heading>
        <div class="flex gap-2">
            <flux:input wire:model="scenarioName" placeholder="Scenario name..." class="flex-1" />
            <flux:button wire:click="saveScenario" variant="primary">Save</flux:button>
        </div>
        @if($this->scenarios->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach($this->scenarios as $scenario)
                    <flux:button size="sm" variant="ghost" wire:click="loadScenario({{ $scenario->id }})" wire:key="scenario-{{ $scenario->id }}">
                        {{ $scenario->name }}
                    </flux:button>
                @endforeach
            </div>
        @endif
    </div>
</div>

@script
<script>
Alpine.data('compensationPlanner', () => ({
    formatNumber(n) {
        return Math.round(n).toLocaleString();
    },
    modelLabel(model) {
        return { commission: 'Commission Only', flat_plus: 'Flat + Commission', flat_only: 'Flat Only' }[model];
    },
    get annualKnocks() { return this.$wire.knocksPerDay * this.$wire.workDaysPerWeek * this.$wire.workWeeksPerYear; },
    get annualAppts() { return this.annualKnocks * (this.$wire.knockToApptRate / 100); },
    get annualSold() { return this.annualAppts * (this.$wire.apptToSoldRate / 100); },
    get annualRevenue() { return this.annualSold * this.$wire.avgJobSize; },

    calcKnockerIncome(model) {
        if (model === 'commission') return this.annualRevenue * (this.$wire.knockerCommissionPct / 100);
        if (model === 'flat_plus') return (this.annualAppts * this.$wire.flatPerEstimate) + (this.annualRevenue * (this.$wire.flatPlusCommissionPct / 100));
        if (model === 'flat_only') return this.annualAppts * this.$wire.flatOnlyPerEstimate;
        return 0;
    },
    calcEstimatorIncome(model) {
        if (model === 'commission') return this.annualRevenue * (this.$wire.estimatorTeamPct / 100);
        if (model === 'flat_plus') return this.annualRevenue * (this.$wire.flatEstimatorPct / 100);
        if (model === 'flat_only') return this.annualRevenue * (this.$wire.flatOnlyEstimatorPct / 100);
        return 0;
    },
    calcManagerIncome(model) {
        if (model === 'commission') return this.annualRevenue * (this.$wire.regionManagerPct / 100);
        if (model === 'flat_plus') return this.annualRevenue * (this.$wire.flatRegionPct / 100);
        if (model === 'flat_only') return this.annualRevenue * (this.$wire.flatOnlyRegionPct / 100);
        return 0;
    },
    calcTotalPct(model) {
        if (this.annualRevenue === 0) return 0;
        let total = this.calcKnockerIncome(model) + this.calcEstimatorIncome(model) + this.calcManagerIncome(model);
        return (total / this.annualRevenue) * 100;
    },
    calcRequiredKnocksPerDay(model) {
        let incomePerKnock = this.calcKnockerIncome(model) / Math.max(this.annualKnocks, 1);
        if (incomePerKnock <= 0) return '---';
        let requiredKnocks = this.$wire.targetIncome / incomePerKnock;
        let perDay = requiredKnocks / (this.$wire.workDaysPerWeek * this.$wire.workWeeksPerYear);
        return Math.ceil(perDay);
    },
    calcRequiredApptsPerWeek(model) {
        let kpd = this.calcRequiredKnocksPerDay(model);
        if (kpd === '---') return '---';
        return Math.ceil(kpd * this.$wire.workDaysPerWeek * (this.$wire.knockToApptRate / 100));
    },
    calcRequiredJobsPerMonth(model) {
        let kpd = this.calcRequiredKnocksPerDay(model);
        if (kpd === '---') return '---';
        let dailyJobs = kpd * (this.$wire.knockToApptRate / 100) * (this.$wire.apptToSoldRate / 100);
        return Math.round(dailyJobs * this.$wire.workDaysPerWeek * 4.33 * 10) / 10;
    },
}));
</script>
@endscript
