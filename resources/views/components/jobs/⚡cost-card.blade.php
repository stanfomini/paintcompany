<?php

use App\Models\Job;
use App\Models\JobCostCard;
use App\Services\AllocationEvaluationService;
use App\Services\EstimateCostBreakdownService;
use App\Services\EstimateTierPricingService;
use App\Services\JobCostCardService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Job $job;
    public ?JobCostCard $card = null;

    public string $activeTab = 'projected';

    public ?int $selectedPackageId = null;

    public float $whatIfMargin = 50;

    public bool $showWhatIf = false;

    // Actuals form fields
    public ?string $actualMaterialCost = null;
    public ?string $actualLaborCost = null;
    public ?string $actualSubcontractorCost = null;
    public ?string $actualOtherCost = null;
    public ?string $actualNotes = null;

    protected EstimateCostBreakdownService $service;

    public function boot(EstimateCostBreakdownService $service): void
    {
        if (! auth()->user()?->can('financials.view_cost_breakdown')) {
            abort(403);
        }

        $this->service = $service;
    }

    public function mount(Job $job): void
    {
        $this->job = $job->load([
            'deal.customer',
            'estimate.areas.surfaces.productionRate',
            'estimate.areas.addons',
            'estimate.items',
            'estimate.job.crews.crewProfile',
            'invoices.payments',
        ]);

        $this->card = JobCostCard::where('job_id', $job->id)
            ->where('is_manual', false)
            ->first();

        if ($this->card) {
            $this->card->load('cardAllocations');
            $this->actualMaterialCost = $this->card->actual_material_cost !== null ? (string) $this->card->actual_material_cost : null;
            $this->actualLaborCost = $this->card->actual_labor_cost !== null ? (string) $this->card->actual_labor_cost : null;
            $this->actualSubcontractorCost = $this->card->actual_subcontractor_cost !== null ? (string) $this->card->actual_subcontractor_cost : null;
            $this->actualOtherCost = $this->card->actual_other_cost !== null ? (string) $this->card->actual_other_cost : null;
            $this->actualNotes = $this->card->actual_notes;
        }

        if ($this->job->estimate) {
            $activePackage = $this->service->getActivePackage($this->job->estimate);
            if ($activePackage) {
                $this->selectedPackageId = $activePackage->id;
            }

            // Set initial what-if margin to current settings
            $currentSettings = $this->service->getCurrentSettings($this->job->estimate);
            $this->whatIfMargin = $currentSettings['margin_percent'];
        }
    }

    #[Computed]
    public function breakdown(): ?array
    {
        if (! $this->job->estimate) {
            return null;
        }

        // Build allocation context from the card's job data (payment methods, scope, etc.)
        $allocationContext = [];
        if ($this->card) {
            $allocationContext = app(AllocationEvaluationService::class)->buildContext($this->card);
        }

        if ($this->selectedPackageId) {
            $package = $this->job->estimate->packages()->where('id', $this->selectedPackageId)->first();
            if ($package) {
                return $this->service->calculatePackageBreakdown($this->job->estimate, $package, $allocationContext);
            }
        }

        return $this->service->calculateBreakdown($this->job->estimate, $allocationContext);
    }

    #[Computed]
    public function packages(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->job->estimate) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return $this->job->estimate->packages()->orderBy('sort_order')->get();
    }

    #[Computed]
    public function comparison(): ?array
    {
        if (! $this->job->estimate) {
            return null;
        }

        $snapshot = $this->job->estimate->settings_snapshot;

        if (empty($snapshot)) {
            return null;
        }

        $current = $this->service->getCurrentSettings($this->job->estimate);

        return $this->service->compareSnapshots($snapshot, $current);
    }

    #[Computed]
    public function whatIfBreakdown(): ?array
    {
        if (! $this->job->estimate) {
            return null;
        }

        return $this->service->calculateWhatIfScenario($this->job->estimate, $this->whatIfMargin);
    }

    #[Computed]
    public function requiredMarginForTarget(): ?array
    {
        if (! $this->job->estimate) {
            return null;
        }

        $breakdown = $this->breakdown;
        if (! $breakdown) {
            return null;
        }

        $currentSettings = $this->service->getCurrentSettings($this->job->estimate);

        return $this->service->calculateRequiredMarginForTarget(
            $currentSettings['net_profit_margin_percent'],
            ['cogs' => $breakdown['cogs']['total']],
            $currentSettings
        );
    }

    #[Computed]
    public function tierComparison(): ?array
    {
        if (! $this->job->estimate || ! $this->job->estimate->hasPaintTiers()) {
            return null;
        }

        return app(EstimateTierPricingService::class)->calculateTierTotals($this->job->estimate);
    }

    public function selectPackage(int $packageId): void
    {
        if ($this->job->estimate) {
            $package = $this->job->estimate->packages()->where('id', $packageId)->first();
            if ($package) {
                $this->selectedPackageId = $packageId;
                unset($this->breakdown);
            }
        }
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function toggleWhatIf(): void
    {
        $this->showWhatIf = ! $this->showWhatIf;
    }

    public function updateWhatIfMargin(float $margin): void
    {
        $this->whatIfMargin = max(0, min(100, $margin));
    }

    public function saveActuals(): void
    {
        abort_unless(auth()->user()?->can('financials.view_cost_breakdown'), 403);

        if (! $this->card) {
            session()->flash('error', __('No cost card found for this job.'));

            return;
        }

        $service = new JobCostCardService;
        $service->updateActuals($this->card, [
            'actual_material_cost' => $this->actualMaterialCost,
            'actual_labor_cost' => $this->actualLaborCost,
            'actual_subcontractor_cost' => $this->actualSubcontractorCost,
            'actual_other_cost' => $this->actualOtherCost,
            'actual_notes' => $this->actualNotes,
        ]);

        $this->card->refresh();
        session()->flash('success', __('Actuals saved successfully.'));
    }

    public function syncPayments(): void
    {
        abort_unless(auth()->user()?->can('financials.view_cost_breakdown'), 403);

        if (! $this->card) {
            session()->flash('error', __('No cost card found for this job.'));

            return;
        }

        $service = new JobCostCardService;
        $service->syncPaymentSummary($this->job);

        $this->card->refresh();
        unset($this->invoices);
        session()->flash('success', __('Payment summary synced.'));
    }

    #[Computed]
    public function invoices()
    {
        return $this->job->invoices()
            ->with('payments')
            ->latest()
            ->get();
    }

    #[Computed]
    public function settingsDrift(): array
    {
        if (! $this->card || ! $this->card->settings_snapshot) {
            return [];
        }

        $snapshot = $this->card->settings_snapshot;
        $settings = \App\Models\CompanySetting::getInstance();
        $drifts = [];

        $checks = [
            'interior_hourly_wage' => 'Interior Hourly Wage',
            'exterior_hourly_wage' => 'Exterior Hourly Wage',
            'interior_margin_percent' => 'Interior Margin %',
            'exterior_margin_percent' => 'Exterior Margin %',
            'payroll_burden_multiplier' => 'Payroll Burden Multiplier',
            'interior_cleanup_ratio' => 'Interior Cleanup Ratio',
            'exterior_cleanup_ratio' => 'Exterior Cleanup Ratio',
            'interior_sundries_percent' => 'Interior Sundries %',
            'exterior_sundries_percent' => 'Exterior Sundries %',
        ];

        foreach ($checks as $key => $label) {
            $snapshotVal = $snapshot[$key] ?? null;
            $currentVal = $settings->{$key} ?? null;

            if ($snapshotVal !== null && $currentVal !== null && (float) $snapshotVal !== (float) $currentVal) {
                $drifts[] = [
                    'label' => $label,
                    'snapshot' => $snapshotVal,
                    'current' => $currentVal,
                ];
            }
        }

        return $drifts;
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header (mobile-responsive) --}}
        <div class="flex flex-col gap-3 mb-6 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3 min-w-0">
                <flux:button variant="ghost" :href="route('jobs.show', $job)" icon="arrow-left" wire:navigate class="shrink-0">
                    <span class="hidden sm:inline">{{ __('Back to Job') }}</span>
                </flux:button>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <flux:heading size="xl">{{ __('Cost Card') }}</flux:heading>
                        @if ($card && $card->scope_type)
                            <flux:badge size="sm" color="{{ $card->scope_type === 'interior' ? 'blue' : 'amber' }}">
                                {{ ucfirst($card->scope_type) }}
                            </flux:badge>
                        @endif
                    </div>
                    <flux:text class="text-zinc-500 truncate">
                        {{ $job->job_number }}
                        @if ($this->job->estimate)
                            &mdash; {{ $this->job->estimate->estimate_number }}
                        @endif
                    </flux:text>
                </div>
            </div>
            @if ($this->job->estimate)
                <div class="flex items-center gap-2 shrink-0">
                    @can('financials.view_painter_breakdown')
                        <flux:button :href="route('jobs.painter-breakdown', $job)" icon="clipboard-document-list" variant="ghost" size="sm" wire:navigate>
                            <span class="hidden sm:inline">{{ __("Painter's Breakdown") }}</span>
                        </flux:button>
                    @endcan
                    <flux:button :href="route('estimates.breakdown.csv', $this->job->estimate)" icon="table-cells" variant="ghost" size="sm">
                        <span class="hidden sm:inline">{{ __('Export CSV') }}</span>
                    </flux:button>
                    <flux:button :href="route('estimates.breakdown.pdf', $this->job->estimate)" target="_blank" icon="document-arrow-down" variant="ghost" size="sm">
                        <span class="hidden sm:inline">{{ __('Export PDF') }}</span>
                    </flux:button>
                    <flux:button icon="printer" variant="ghost" size="sm" onclick="window.print()">
                        <span class="hidden sm:inline">{{ __('Print') }}</span>
                    </flux:button>
                </div>
            @endif
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- Internal Only Warning --}}
        <flux:callout variant="warning" class="mb-6 print:hidden">
            <flux:icon name="eye-slash" class="w-5 h-5" />
            <div>
                <strong>{{ __('Internal Only') }}</strong> - {{ __('This breakdown reveals true costs and profit margins. Never share with customers.') }}
            </div>
        </flux:callout>

        {{-- Tab Buttons --}}
        @php
            $tabs = ['projected' => __('Projected Costs')];
            if ($this->comparison) {
                $tabs['comparison'] = __('Settings Comparison');
            }
            $tabs['actuals'] = __('Actuals & Variance');
            $tabs['payments'] = __('Payments');
        @endphp
        <div class="mb-6 inline-flex flex-wrap rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
            @foreach ($tabs as $tabKey => $tabLabel)
                <button
                    wire:click="setActiveTab('{{ $tabKey }}')"
                    class="rounded-md px-3 py-2 text-sm font-medium transition-colors sm:px-4 {{ $activeTab === $tabKey ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                >
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>

        {{-- Tab 1: Projected Costs (Live Breakdown) --}}
        @if ($activeTab === 'projected')
            @php $breakdown = $this->breakdown; @endphp

            @if (! $breakdown)
                <flux:callout variant="outline" icon="calculator">
                    <flux:callout.heading>{{ __('No Estimate Linked') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Live breakdown requires an estimate linked to this job. Use the stored cost card data in the Actuals tab instead.') }}
                    </flux:callout.text>
                </flux:callout>
            @else
                {{-- Tier Comparison (Good/Better/Best) --}}
                @if ($this->tierComparison)
                    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Good / Better / Best Comparison') }}</h3>
                        <div class="grid grid-cols-3 gap-4">
                            @foreach (['good' => 'Good', 'better' => 'Better', 'best' => 'Best'] as $tierKey => $tierLabel)
                                <div class="rounded-lg border border-zinc-200 p-3 text-center dark:border-zinc-700 {{ $tierKey === 'better' ? 'ring-2 ring-blue-500' : '' }}">
                                    <div class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __($tierLabel) }}</div>
                                    <div class="mt-1 text-lg font-bold text-zinc-900 dark:text-white">${{ number_format($this->tierComparison[$tierKey], 2) }}</div>
                                    @if ($tierKey !== 'good')
                                        <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            +${{ number_format($this->tierComparison[$tierKey] - $this->tierComparison['good'], 2) }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Package Switcher --}}
                @if ($this->packages->count() > 0)
                    <div class="mb-6">
                        <div class="grid gap-3 {{ $this->packages->count() === 2 ? 'grid-cols-2' : ($this->packages->count() >= 3 ? 'grid-cols-3' : 'grid-cols-1') }}">
                            @foreach ($this->packages as $pkg)
                                @php
                                    $isActive = $selectedPackageId === $pkg->id;
                                    $isSigned = $pkg->signed_at !== null;
                                    $isSelected = $pkg->is_selected;
                                    $isRecommended = $pkg->is_recommended;
                                @endphp
                                <button
                                    wire:click="selectPackage({{ $pkg->id }})"
                                    wire:key="pkg-{{ $pkg->id }}"
                                    class="relative rounded-lg border-2 p-4 text-left transition-all {{ $isActive ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 ring-1 ring-blue-500' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 hover:border-zinc-300 dark:hover:border-zinc-600' }}"
                                >
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="font-semibold text-sm {{ $isActive ? 'text-blue-900 dark:text-blue-100' : 'text-zinc-900 dark:text-white' }}">
                                            {{ $pkg->name ?: ($pkg->tier_label ?? ucfirst($pkg->tier)) }}
                                        </span>
                                        <div class="flex items-center gap-1">
                                            @if ($isSigned)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                    {{ __('Signed') }}
                                                </span>
                                            @elseif ($isSelected)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                    {{ __('Selected') }}
                                                </span>
                                            @elseif ($isRecommended)
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                                    {{ __('Recommended') }}
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="text-lg font-bold {{ $isActive ? 'text-blue-900 dark:text-blue-100' : 'text-zinc-900 dark:text-white' }}">
                                        ${{ number_format($pkg->total, 2) }}
                                    </div>
                                    @if ($pkg->warranty_years > 0)
                                        <div class="text-[11px] {{ $isActive ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}">
                                            {{ $pkg->warranty_years }}-{{ __('Year Warranty') }}
                                        </div>
                                    @endif
                                    @if ($isActive)
                                        <div class="absolute -top-px -left-px -right-px h-1 bg-blue-500 rounded-t-lg"></div>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Warranty Reserve Status --}}
                @if (isset($breakdown['warranty_reserve']) && $breakdown['warranty_reserve'])
                    @php $warranty = $breakdown['warranty_reserve']; @endphp
                    <div class="mb-6 border rounded-lg p-4 {{ $warranty['status'] === 'active' ? 'bg-purple-50 dark:bg-purple-900/20 border-purple-200 dark:border-purple-800' : ($warranty['status'] === 'not_created' ? 'bg-zinc-50 dark:bg-zinc-800 border-zinc-200 dark:border-zinc-700' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800') }}">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="flex-shrink-0">
                                    @if ($warranty['status'] === 'active')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                            <svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                            {{ __('Warranty Active') }}
                                        </span>
                                    @elseif ($warranty['status'] === 'not_created')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                            {{ __('Warranty Pending') }}
                                        </span>
                                    @elseif ($warranty['status'] === 'expired')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                            {{ __('Warranty Expired') }}
                                        </span>
                                    @elseif ($warranty['status'] === 'fully_claimed')
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                            {{ __('Fully Claimed') }}
                                        </span>
                                    @endif
                                </div>
                                <div>
                                    <span class="font-semibold">
                                        {{ $warranty['warranty_years'] }}-{{ __('Year Warranty Reserve') }}
                                    </span>
                                    @if (isset($warranty['warranty_expires_at']))
                                        <span class="text-sm ml-2">
                                            ({{ __('Expires:') }} {{ $warranty['warranty_expires_at'] }})
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right">
                                @if ($warranty['status'] === 'not_created')
                                    <div class="text-lg font-bold">${{ number_format($warranty['expected_reserve'], 2) }}</div>
                                    <div class="text-xs text-zinc-500">{{ __('Expected reserve') }}</div>
                                @else
                                    <div class="text-lg font-bold">${{ number_format($warranty['remaining_balance'] ?? 0, 2) }}</div>
                                    <div class="text-xs">
                                        {{ __('Claimed:') }} ${{ number_format($warranty['amount_claimed'] ?? 0, 2) }} ({{ number_format($warranty['claim_percentage'] ?? 0, 1) }}%)
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Summary Cards --}}
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Customer Price') }}</div>
                        <div class="text-2xl font-bold text-zinc-900 dark:text-white">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</div>
                        <div class="text-xs text-zinc-400">{{ __('COGS ratio:') }} {{ number_format($breakdown['pricing']['margin_percent'], 1) }}%</div>
                    </div>
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Gross Profit') }}</div>
                        <div class="text-2xl font-bold {{ $breakdown['gross_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            ${{ number_format($breakdown['gross_profit']['amount'], 2) }}
                        </div>
                        <div class="text-xs text-zinc-400">{{ number_format($breakdown['gross_profit']['percent'], 1) }}% {{ __('of price') }}</div>
                    </div>
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Net Profit') }}</div>
                        <div class="text-2xl font-bold {{ $breakdown['net_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            ${{ number_format($breakdown['net_profit']['amount'], 2) }}
                        </div>
                        <div class="text-xs {{ $breakdown['net_profit']['meets_target'] ? 'text-green-500' : 'text-red-500' }}">
                            {{ number_format($breakdown['net_profit']['percent'], 1) }}% (target: {{ number_format($breakdown['net_profit']['target_percent'], 1) }}%)
                        </div>
                    </div>
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Target Delta') }}</div>
                        <div class="text-2xl font-bold {{ $breakdown['net_profit']['delta_percent'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                            {{ $breakdown['net_profit']['delta_percent'] >= 0 ? '+' : '' }}{{ number_format($breakdown['net_profit']['delta_percent'], 1) }}%
                        </div>
                        <div class="text-xs text-zinc-400">
                            @if ($breakdown['net_profit']['meets_target'])
                                {{ __('Meets target') }}
                            @else
                                {{ __('Below target') }}
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Financial Ledger --}}
                <div class="space-y-6">
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <div class="bg-zinc-100 dark:bg-zinc-900 px-6 py-3 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:heading size="sm">{{ __('Financial Ledger') }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500">{{ __('Every dollar tracked to the cent') }}</flux:text>
                        </div>

                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            {{-- REVENUE (CREDIT) --}}
                            <div class="px-6 py-4 bg-green-50 dark:bg-green-900/10">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="text-xs font-medium text-green-600 dark:text-green-400 uppercase tracking-wider">{{ __('Credit') }}</span>
                                        <div class="text-lg font-semibold text-green-700 dark:text-green-300">{{ __('Revenue (Customer Price)') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-bold text-green-700 dark:text-green-300">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</div>
                                    </div>
                                </div>
                            </div>

                            {{-- DIRECT COSTS (COGS) - DEBIT --}}
                            <div class="px-6 py-4">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <span class="text-xs font-medium text-red-600 dark:text-red-400 uppercase tracking-wider">{{ __('Debit') }}</span>
                                        <div class="text-lg font-semibold">{{ __('Direct Costs (COGS)') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xl font-bold text-red-600 dark:text-red-400">(${{ number_format($breakdown['cogs']['total'], 2) }})</div>
                                    </div>
                                </div>
                                @php
                                    $baseWage = $breakdown['settings_used']['hourly_rate_override'] ?? $breakdown['settings_used']['hourly_wage'];
                                    $effectiveRate = $baseWage * $breakdown['settings_used']['payroll_burden_multiplier'];
                                @endphp
                                <div class="space-y-2 pl-4 border-l-2 border-zinc-200 dark:border-zinc-700">
                                    <div class="flex justify-between text-sm">
                                        <div class="text-zinc-600 dark:text-zinc-400">
                                            {{ __('Labor') }} ({{ number_format($breakdown['totals']['labor_hours'], 1) }} {{ __('hrs') }} × ${{ number_format($effectiveRate, 2) }})
                                            <span class="text-xs text-zinc-400 block">{{ __('Includes payroll burden') }} ({{ number_format($breakdown['settings_used']['payroll_burden_multiplier'], 2) }}×)</span>
                                        </div>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['labor_cost'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Cleanup') }} ({{ number_format($breakdown['totals']['cleanup_hours'], 2) }} {{ __('hrs') }} × ${{ number_format($effectiveRate, 2) }})</span>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['cleanup_cost'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Sundries') }}</span>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['sundries_cost'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Materials') }}</span>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['material_cost'], 2) }}</span>
                                    </div>
                                    @if (($breakdown['cogs']['material_tax'] ?? 0) > 0)
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Material Tax') }} ({{ number_format($breakdown['settings_used']['material_tax_rate'], 1) }}%)</span>
                                            <span class="font-mono">${{ number_format($breakdown['cogs']['material_tax'], 2) }}</span>
                                        </div>
                                    @endif
                                    @if ($breakdown['cogs']['labor_tax'] > 0)
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Labor Tax') }}</span>
                                            <span class="font-mono">${{ number_format($breakdown['cogs']['labor_tax'], 2) }}</span>
                                        </div>
                                    @endif
                                    @if ($breakdown['cogs']['addon_cost'] > 0)
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Nested Areas / Addons') }}</span>
                                            <span class="font-mono">${{ number_format($breakdown['cogs']['addon_cost'], 2) }}</span>
                                        </div>
                                        @if ($breakdown['cogs']['addon_labor_hours'] > 0)
                                            <div class="flex justify-between text-xs text-zinc-500 pl-4">
                                                <span>{{ __('Addon Labor') }} ({{ number_format($breakdown['cogs']['addon_labor_hours'], 1) }} {{ __('hrs') }})</span>
                                                <span class="font-mono">${{ number_format($breakdown['cogs']['addon_labor_cost'], 2) }}</span>
                                            </div>
                                        @endif
                                        @if ($breakdown['cogs']['addon_material_cost'] > 0)
                                            <div class="flex justify-between text-xs text-zinc-500 pl-4">
                                                <span>{{ __('Addon Materials') }}</span>
                                                <span class="font-mono">${{ number_format($breakdown['cogs']['addon_material_cost'], 2) }}</span>
                                            </div>
                                        @endif
                                    @endif
                                    @if (($breakdown['cogs']['warranty_price'] ?? 0) > 0)
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Warranty Reserve') }}</span>
                                            <span class="font-mono">${{ number_format($breakdown['cogs']['warranty_price'], 2) }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- ADD-ON SERVICES WITH VENDOR COST SPLIT --}}
                            @if (count($breakdown['items'] ?? []) > 0)
                                <div class="px-6 py-4">
                                    <div class="flex justify-between items-center mb-4">
                                        <div>
                                            <span class="text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">{{ __('Add-on Services') }}</span>
                                            <div class="text-lg font-semibold">{{ __('Line Items') }} ({{ count($breakdown['items']) }})</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-sm text-zinc-500">{{ __('Price:') }} ${{ number_format($breakdown['cogs']['items_total'], 2) }}</div>
                                            <div class="text-sm text-zinc-500">{{ __('Cost:') }} ${{ number_format($breakdown['cogs']['items_vendor_cost'], 2) }}</div>
                                            <div class="font-bold text-green-600">{{ __('Profit:') }} ${{ number_format($breakdown['cogs']['items_profit'], 2) }}</div>
                                        </div>
                                    </div>
                                    <div class="space-y-2 pl-4 border-l-2 border-orange-200 dark:border-orange-800">
                                        @foreach ($breakdown['items'] as $item)
                                            <div class="flex justify-between text-sm">
                                                <div class="flex-1">
                                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $item['description'] }}</span>
                                                    <span class="text-zinc-500 text-xs ml-1">({{ number_format($item['quantity'], 0) }} {{ $item['unit'] }})</span>
                                                </div>
                                                <div class="text-right flex items-center gap-4">
                                                    <span class="font-mono">${{ number_format($item['total'], 2) }}</span>
                                                    @if ($item['has_vendor_cost'])
                                                        <span class="text-xs px-1.5 py-0.5 rounded {{ $item['profit'] > 0 ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-zinc-100 text-zinc-600' }}">
                                                            +${{ number_format($item['profit'], 2) }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- GROSS PROFIT --}}
                            <div class="px-6 py-4 bg-zinc-50 dark:bg-zinc-900">
                                <div class="flex justify-between items-center">
                                    <div class="text-lg font-semibold">{{ __('Gross Profit') }}</div>
                                    <div class="text-right">
                                        <div class="text-xl font-bold {{ $breakdown['gross_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                            ${{ number_format($breakdown['gross_profit']['amount'], 2) }}
                                        </div>
                                        <div class="text-sm text-zinc-500">{{ number_format($breakdown['gross_profit']['percent'], 1) }}% {{ __('margin') }}</div>
                                    </div>
                                </div>
                            </div>

                            {{-- ALLOCATIONS (DEBIT) --}}
                            <div class="px-6 py-4">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <span class="text-xs font-medium text-red-600 dark:text-red-400 uppercase tracking-wider">{{ __('Debit') }}</span>
                                        <div class="text-lg font-semibold">{{ __('Business Allocations') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xl font-bold text-red-600 dark:text-red-400">(${{ number_format($breakdown['allocations']['total'], 2) }})</div>
                                        <div class="text-sm text-zinc-500">{{ number_format($breakdown['allocations']['total_percent'] ?? 0, 1) }}% {{ __('of revenue') }}</div>
                                    </div>
                                </div>
                                <div class="space-y-2 pl-4 border-l-2 border-zinc-200 dark:border-zinc-700">
                                    @if (isset($breakdown['allocations']['items']))
                                        @foreach ($breakdown['allocations']['items'] as $alloc)
                                            <div class="flex justify-between text-sm">
                                                <span class="text-zinc-600 dark:text-zinc-400">
                                                    {{ $alloc['name'] }}
                                                    @if ($alloc['type'] === 'percent_of_revenue')
                                                        ({{ number_format($alloc['value'], 1) }}%)
                                                    @elseif ($alloc['type'] === 'fixed_per_job')
                                                        <span class="text-xs text-zinc-500">({{ __('fixed/job') }})</span>
                                                    @elseif ($alloc['type'] === 'per_labor_hour')
                                                        <span class="text-xs text-zinc-500">(${{ number_format($alloc['value'], 2) }}/{{ __('hr') }})</span>
                                                    @endif
                                                </span>
                                                <span class="font-mono">${{ number_format($alloc['amount'], 2) }}</span>
                                            </div>
                                        @endforeach
                                    @else
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Marketing') }} ({{ number_format($breakdown['allocations']['marketing']['rate'], 1) }}%)</span>
                                            <span class="font-mono">${{ number_format($breakdown['allocations']['marketing']['amount'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Sales') }} ({{ number_format($breakdown['allocations']['sales']['rate'], 1) }}%)</span>
                                            <span class="font-mono">${{ number_format($breakdown['allocations']['sales']['amount'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Production Mgmt') }} ({{ number_format($breakdown['allocations']['production_management']['rate'], 1) }}%)</span>
                                            <span class="font-mono">${{ number_format($breakdown['allocations']['production_management']['amount'], 2) }}</span>
                                        </div>
                                    @endif
                                </div>
                                @if (isset($breakdown['allocations']['overhead']) && !($breakdown['allocations']['overhead']['informational'] ?? false) === false)
                                    <div class="mt-3 pt-3 border-t border-dashed border-zinc-200 dark:border-zinc-700">
                                        <div class="flex justify-between text-sm text-zinc-500">
                                            <span>{{ __('General Overhead') }} ({{ number_format($breakdown['allocations']['overhead']['rate'], 1) }}%) <span class="text-xs italic">{{ __('informational only') }}</span></span>
                                            <span class="font-mono">${{ number_format($breakdown['allocations']['overhead']['amount'], 2) }}</span>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            {{-- CREW LEAD BONUS --}}
                            @if (isset($breakdown['crew_bonus']) && $breakdown['crew_bonus'])
                                @php $crewBonus = $breakdown['crew_bonus']; @endphp
                                <div class="px-6 py-4">
                                    <div class="flex justify-between items-center mb-3">
                                        <div>
                                            <span class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">{{ __('Conditional Bonus') }}</span>
                                            <div class="text-lg font-semibold">{{ __('Crew Lead Bonus') }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-xl font-bold text-green-600 dark:text-green-400">${{ number_format($crewBonus['bonus_amount'], 2) }}</div>
                                            <div class="text-xs text-zinc-500">{{ $crewBonus['crew_name'] }} ({{ number_format($crewBonus['bonus_percent'], 1) }}%)</div>
                                        </div>
                                    </div>
                                    <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                                        <div class="text-xs font-medium text-amber-700 dark:text-amber-300 mb-2">{{ __('How to Earn This Bonus:') }}</div>
                                        <ul class="text-xs text-zinc-600 dark:text-zinc-400 space-y-1 list-disc list-inside">
                                            <li>{{ __('Actively use and engage with the job software throughout the project') }}</li>
                                            <li>{{ __('Be active in the Job Chat - communicate with the team and customer') }}</li>
                                            <li>{{ __('Upload, view, and manage team and customer photos and communications') }}</li>
                                            <li>{{ __('Participate in Job Prep to help build a plan the team can follow') }}</li>
                                            <li>{{ __('Follow the plan as outlined during prep') }}</li>
                                            <li>{{ __('Do not promote personal business on the job site') }}</li>
                                            <li>{{ __('Dress appropriately and maintain a professional appearance') }}</li>
                                            <li>{{ __('Ensure all touchups are completed before job closeout') }}</li>
                                        </ul>
                                    </div>
                                </div>
                            @endif

                            {{-- NET PROFIT --}}
                            <div class="px-6 py-4 {{ $breakdown['net_profit']['is_profitable'] ? 'bg-green-100 dark:bg-green-900/30' : 'bg-red-100 dark:bg-red-900/30' }}">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="text-xs font-medium uppercase tracking-wider {{ $breakdown['net_profit']['is_profitable'] ? 'text-green-600' : 'text-red-600' }}">{{ __('Net Result') }}</span>
                                        <div class="text-lg font-semibold">{{ __('Net Profit') }}</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-bold {{ $breakdown['net_profit']['is_profitable'] ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                            ${{ number_format($breakdown['net_profit']['amount'], 2) }}
                                        </div>
                                        <div class="text-sm {{ $breakdown['net_profit']['meets_target'] ? 'text-green-600' : 'text-amber-600' }}">
                                            {{ number_format($breakdown['net_profit']['percent'], 1) }}% (target: {{ number_format($breakdown['net_profit']['target_percent'], 1) }}%)
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- NET PROFIT AFTER CREW LEAD BONUS --}}
                            @if (isset($breakdown['crew_bonus']) && $breakdown['crew_bonus'])
                                <div class="px-6 py-4 border-t border-dashed border-zinc-200 dark:border-zinc-700">
                                    <div class="flex justify-between items-center">
                                        <div>
                                            <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">{{ __('If Bonus Paid') }}</span>
                                            <div class="text-lg font-semibold">{{ __('Net Profit After Bonus') }}</div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-2xl font-bold {{ ($breakdown['net_profit']['after_bonus_amount'] ?? 0) >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                                ${{ number_format($breakdown['net_profit']['after_bonus_amount'], 2) }}
                                            </div>
                                            <div class="text-sm text-zinc-500">
                                                {{ number_format($breakdown['net_profit']['after_bonus_percent'], 1) }}% {{ __('margin') }}
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            {{-- VERIFICATION ROW --}}
                            @php
                                $totalDebits = $breakdown['cogs']['total'] + $breakdown['allocations']['total'];
                                $verification = $totalDebits + $breakdown['net_profit']['amount'];
                                $difference = abs($verification - $breakdown['pricing']['customer_price']);
                                $isBalanced = $difference < 0.02;
                            @endphp
                            <div class="px-6 py-3 bg-zinc-800 dark:bg-zinc-950 text-white">
                                <div class="flex justify-between items-center text-sm">
                                    <div class="flex items-center gap-2">
                                        @if ($isBalanced)
                                            <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                            <span class="text-green-400">{{ __('Verification Passed') }}</span>
                                        @else
                                            <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                            </svg>
                                            <span class="text-red-400">{{ __('Verification Failed') }} ({{ __('off by') }} ${{ number_format($difference, 2) }})</span>
                                        @endif
                                    </div>
                                    <div class="font-mono text-xs">
                                        COGS (${{ number_format($breakdown['cogs']['total'], 2) }}) + Alloc (${{ number_format($breakdown['allocations']['total'], 2) }}) + Net (${{ number_format($breakdown['net_profit']['amount'], 2) }}) = ${{ number_format($verification, 2) }}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Per-Area Breakdown Table --}}
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-4">{{ __('Per-Area Detail') }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3">
                            {{ __('Numbers from each area\'s model - the same source as the estimate page.') }}
                        </flux:text>
                        @php $hasTierPricing = !empty($breakdown['areas'][0]['tier_pricing'] ?? null); @endphp
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase">{{ __('Area') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase">{{ __('Hours') }}</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase">{{ __('COGS') }}</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-zinc-500 uppercase">{{ __('Margin') }}</th>
                                        @if ($hasTierPricing)
                                            <th class="px-4 py-3 text-right text-xs font-medium text-green-600 uppercase">{{ __('Good') }}</th>
                                            <th class="px-4 py-3 text-right text-xs font-medium text-blue-600 uppercase">{{ __('Better') }}</th>
                                            <th class="px-4 py-3 text-right text-xs font-medium text-purple-600 uppercase">{{ __('Best') }}</th>
                                        @else
                                            <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase">{{ __('Customer Price') }}</th>
                                        @endif
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                    @foreach ($breakdown['areas'] as $area)
                                        <tr>
                                            <td class="px-4 py-3 text-sm">
                                                <span class="font-medium">{{ $area['name'] }}</span>
                                                <span class="text-xs text-zinc-500 ml-1">({{ ucfirst($area['scope_type']) }})</span>
                                                @if ($area['is_fixed_price'])
                                                    <span class="ml-1 px-1.5 py-0.5 text-xs bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300 rounded">{{ __('Fixed') }}</span>
                                                @endif
                                                @if ($area['has_custom_pricing'] ?? false)
                                                    <span class="ml-1 px-1.5 py-0.5 text-xs bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded">{{ __('Custom') }}</span>
                                                @endif
                                            </td>
                                            <td class="px-4 py-3 text-sm text-right">{{ number_format($area['detail']['labor_hours'] + $area['detail']['cleanup_hours'] + ($area['detail']['addon_labor_hours'] ?? 0), 1) }}</td>
                                            <td class="px-4 py-3 text-sm text-right">${{ number_format($area['cogs'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-center">{{ number_format($area['margin_percent'], 0) }}%</td>
                                            @if ($hasTierPricing && isset($area['tier_pricing']))
                                                <td class="px-4 py-3 text-sm text-right text-green-700 dark:text-green-400">${{ number_format($area['tier_pricing']['good']['customer_price'], 2) }}</td>
                                                <td class="px-4 py-3 text-sm text-right font-medium text-blue-700 dark:text-blue-400">${{ number_format($area['tier_pricing']['better']['customer_price'], 2) }}</td>
                                                <td class="px-4 py-3 text-sm text-right text-purple-700 dark:text-purple-400">${{ number_format($area['tier_pricing']['best']['customer_price'], 2) }}</td>
                                            @elseif ($hasTierPricing)
                                                <td class="px-4 py-3 text-sm text-right" colspan="3">${{ number_format($area['customer_price'], 2) }}</td>
                                            @else
                                                <td class="px-4 py-3 text-sm text-right font-medium">${{ number_format($area['customer_price'], 2) }}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                    @if (($breakdown['items_total'] ?? 0) > 0)
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium">{{ __('Line Items') }} ({{ count($breakdown['items'] ?? []) }})</td>
                                            <td class="px-4 py-3 text-sm text-right">-</td>
                                            <td class="px-4 py-3 text-sm text-right">${{ number_format($breakdown['cogs']['items_vendor_cost'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-center">-</td>
                                            <td class="px-4 py-3 text-sm text-right font-medium">${{ number_format($breakdown['items_total'], 2) }}</td>
                                        </tr>
                                    @endif
                                </tbody>
                                <tfoot>
                                    <tr class="bg-zinc-50 dark:bg-zinc-800 font-bold">
                                        <td class="px-4 py-3 text-sm">{{ __('TOTALS') }}</td>
                                        <td class="px-4 py-3 text-sm text-right">{{ number_format($breakdown['totals']['total_hours'], 1) }}</td>
                                        <td class="px-4 py-3 text-sm text-right">${{ number_format($breakdown['cogs']['total'], 2) }}</td>
                                        <td class="px-4 py-3 text-sm text-center">{{ number_format($breakdown['pricing']['margin_percent'], 0) }}%</td>
                                        @if ($hasTierPricing && $this->tierComparison)
                                            <td class="px-4 py-3 text-sm text-right text-green-700 dark:text-green-400">${{ number_format($this->tierComparison['good'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-right text-blue-700 dark:text-blue-400">${{ number_format($this->tierComparison['better'], 2) }}</td>
                                            <td class="px-4 py-3 text-sm text-right text-purple-700 dark:text-purple-400">${{ number_format($this->tierComparison['best'], 2) }}</td>
                                        @else
                                            <td class="px-4 py-3 text-sm text-right">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</td>
                                        @endif
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    {{-- Per Service Breakdown --}}
                    @php
                        $perService = $this->service->getPerServiceBreakdown($this->job->estimate);
                    @endphp

                    @if (count($perService) > 1)
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                            <flux:heading size="sm" class="mb-4">{{ __('Breakdown by Service') }}</flux:heading>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                @foreach ($perService as $slug => $svc)
                                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4" wire:key="service-{{ $slug }}">
                                        <div class="flex items-center gap-2 mb-3">
                                            <flux:badge size="sm" color="{{ $svc['category_color'] }}">
                                                {{ $svc['category_name'] }}
                                            </flux:badge>
                                            <span class="text-sm text-zinc-500">{{ $svc['margin_percent'] }}% {{ __('margin') }}</span>
                                        </div>
                                        <div class="space-y-1 text-sm">
                                            <div class="flex justify-between">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('COGS') }}</span>
                                                <span class="font-medium font-mono">${{ number_format($svc['cogs'], 2) }}</span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Price') }}</span>
                                                <span class="font-medium font-mono">${{ number_format($svc['price'], 2) }}</span>
                                            </div>
                                            <div class="flex justify-between border-t border-zinc-100 dark:border-zinc-800 pt-1 mt-1">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Gross Profit') }}</span>
                                                <span class="font-medium font-mono text-green-600">${{ number_format($svc['price'] - $svc['cogs'], 2) }}</span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    {{-- Reconciliation --}}
                    @if (isset($breakdown['reconciliation']) && abs($breakdown['reconciliation']['difference']) > 1)
                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                            <flux:heading size="sm" class="mb-4 text-blue-800 dark:text-blue-200">{{ __('Settings Drift Check') }}</flux:heading>
                            <flux:text size="sm" class="text-blue-700 dark:text-blue-300 mb-3">
                                {{ __('Compares the package price (what the customer sees) against what the current settings would calculate. A difference means settings have changed since the package was generated.') }}
                            </flux:text>
                            <div class="space-y-2">
                                <div class="flex justify-between text-sm">
                                    <span>{{ __('Package Price (customer sees)') }}</span>
                                    <span class="font-medium">${{ number_format($breakdown['reconciliation']['stored_subtotal'], 2) }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span>{{ __('Current Settings Would Price At') }}</span>
                                    <span class="font-medium">${{ number_format($breakdown['reconciliation']['calculated_price'], 2) }}</span>
                                </div>
                                <div class="flex justify-between text-sm font-bold pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <span>{{ __('Drift') }}</span>
                                    <span class="{{ $breakdown['reconciliation']['difference'] > 0 ? 'text-amber-600' : 'text-amber-600' }}">
                                        {{ $breakdown['reconciliation']['difference'] > 0 ? '+' : '' }}${{ number_format($breakdown['reconciliation']['difference'], 2) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Settings Reference --}}
                <div class="mt-6" x-data="{ expanded: false }">
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <div class="flex items-center justify-between p-4 cursor-pointer bg-zinc-50 dark:bg-zinc-800/50" @click="expanded = !expanded">
                            <flux:heading size="sm">{{ __('Settings Used') }}</flux:heading>
                            <svg x-show="!expanded" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            <svg x-show="expanded" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                        </div>
                        <div x-show="expanded" x-collapse class="border-t border-zinc-200 dark:border-zinc-700 p-4">
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                <div>
                                    <span class="text-zinc-500">{{ __('Scope:') }}</span>
                                    <span class="font-medium ml-1">{{ ucfirst($breakdown['settings_used']['scope']) }}</span>
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Hourly Wage:') }}</span>
                                    <span class="font-medium ml-1">${{ number_format($breakdown['settings_used']['hourly_rate_override'] ?? $breakdown['settings_used']['hourly_wage'], 2) }}</span>
                                    @if ($breakdown['settings_used']['hourly_rate_override'] ?? null)
                                        <span class="text-xs text-amber-600 dark:text-amber-400">({{ __('override') }})</span>
                                    @endif
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Payroll Burden:') }}</span>
                                    <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['payroll_burden_multiplier'], 2) }}x</span>
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Margin:') }}</span>
                                    <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['margin_percent'], 1) }}%</span>
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Cleanup Ratio:') }}</span>
                                    <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['cleanup_hours_ratio'], 0) }}:1</span>
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Sundries/Hr:') }}</span>
                                    <span class="font-medium ml-1">${{ number_format($breakdown['settings_used']['sundries_cost_per_hour'], 2) }}</span>
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Material Tax:') }}</span>
                                    <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['material_tax_rate'], 1) }}%</span>
                                </div>
                                <div>
                                    <span class="text-zinc-500">{{ __('Target Net Margin:') }}</span>
                                    <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['net_profit_margin_percent'], 1) }}%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- What-If Margin Slider --}}
                <div class="mt-6 print:hidden" x-data="{ showWhatIf: @entangle('showWhatIf') }">
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                        <div class="flex items-center justify-between p-4 cursor-pointer bg-zinc-50 dark:bg-zinc-800/50" @click="showWhatIf = !showWhatIf">
                            <flux:heading size="sm">{{ __('What-If: Margin Rate') }}</flux:heading>
                            <svg x-show="!showWhatIf" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            <svg x-show="showWhatIf" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                        </div>
                        <div x-show="showWhatIf" x-collapse class="border-t border-zinc-200 dark:border-zinc-700 p-4">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                                        {{ __('Margin Rate:') }} {{ number_format($whatIfMargin, 1) }}%
                                    </label>
                                    <input
                                        type="range"
                                        min="0"
                                        max="70"
                                        step="1"
                                        wire:model.live.debounce.300ms="whatIfMargin"
                                        class="w-full h-2 bg-zinc-200 rounded-lg appearance-none cursor-pointer dark:bg-zinc-700"
                                    >
                                    <div class="flex justify-between text-xs text-zinc-500 mt-1">
                                        <span>0%</span>
                                        <span>35%</span>
                                        <span>70%</span>
                                    </div>
                                </div>

                                @if ($this->whatIfBreakdown)
                                    @php $whatIf = $this->whatIfBreakdown; @endphp
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-zinc-50 dark:bg-zinc-900 rounded-lg">
                                        <div>
                                            <div class="text-xs text-zinc-500">{{ __('Customer Price') }}</div>
                                            <div class="text-lg font-bold">${{ number_format($whatIf['pricing']['customer_price'], 2) }}</div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-zinc-500">{{ __('Gross Profit') }}</div>
                                            <div class="text-lg font-bold {{ $whatIf['gross_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                ${{ number_format($whatIf['gross_profit']['amount'], 2) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-zinc-500">{{ __('Net Profit') }}</div>
                                            <div class="text-lg font-bold {{ $whatIf['net_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                                ${{ number_format($whatIf['net_profit']['amount'], 2) }}
                                            </div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-zinc-500">{{ __('Net Margin') }}</div>
                                            <div class="text-lg font-bold {{ $whatIf['net_profit']['meets_target'] ? 'text-green-600' : 'text-amber-600' }}">
                                                {{ number_format($whatIf['net_profit']['percent'], 1) }}%
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                @if ($this->requiredMarginForTarget)
                                    @php $required = $this->requiredMarginForTarget; @endphp
                                    @if ($required['is_achievable'])
                                        <div class="text-sm text-zinc-600 dark:text-zinc-400 bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg">
                                            <strong>{{ __('To achieve') }} {{ number_format($breakdown['net_profit']['target_percent'], 1) }}% {{ __('net margin:') }}</strong>
                                            {{ __('Set margin rate to') }} <strong>{{ number_format($required['required_margin_percent'], 1) }}%</strong>
                                            ({{ __('Customer price:') }} ${{ number_format($required['required_customer_price'], 2) }})
                                        </div>
                                    @else
                                        <div class="text-sm text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-lg">
                                            {{ $required['message'] }}
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Settings Drift Warning (from stored cost card snapshot) --}}
                @if (count($this->settingsDrift) > 0)
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mt-6">
                        <flux:callout.heading>{{ __('Settings Changed Since Estimate') }}</flux:callout.heading>
                        <flux:callout.text>
                            <div class="mt-2 space-y-1">
                                @foreach ($this->settingsDrift as $drift)
                                    <div class="text-sm">
                                        <span class="font-medium">{{ $drift['label'] }}:</span>
                                        <span class="text-red-600 dark:text-red-400 line-through">{{ $drift['snapshot'] }}</span>
                                        <span class="mx-1">&rarr;</span>
                                        <span class="text-emerald-600 dark:text-emerald-400">{{ $drift['current'] }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif

        {{-- Tab 2: Settings Comparison --}}
        @elseif ($activeTab === 'comparison' && $this->comparison)
            @php $comp = $this->comparison; @endphp
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">{{ __('Settings Comparison') }}</flux:heading>

                @if ($comp['has_changes'])
                    <div class="mb-4 text-sm text-amber-600 dark:text-amber-400">
                        {{ __('Settings have changed since this estimate was created.') }}
                        @if ($comp['original_captured_at'])
                            <span class="text-zinc-500">({{ __('Captured:') }} {{ \Carbon\Carbon::parse($comp['original_captured_at'])->format('M j, Y g:i A') }})</span>
                        @endif
                    </div>
                @else
                    <div class="mb-4 text-sm text-green-600 dark:text-green-400">
                        {{ __('No significant changes in settings since estimate creation.') }}
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead>
                            <tr>
                                <th class="py-2 text-left text-xs font-medium text-zinc-500 uppercase">{{ __('Setting') }}</th>
                                <th class="py-2 text-right text-xs font-medium text-zinc-500 uppercase">{{ __('Original') }}</th>
                                <th class="py-2 text-right text-xs font-medium text-zinc-500 uppercase">{{ __('Current') }}</th>
                                <th class="py-2 text-right text-xs font-medium text-zinc-500 uppercase">{{ __('Change') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($comp['comparisons'] as $key => $item)
                                <tr class="{{ $item['changed'] ? 'bg-amber-50 dark:bg-amber-900/20' : '' }}">
                                    <td class="py-2 text-sm">{{ $item['label'] }}</td>
                                    <td class="py-2 text-sm text-right">
                                        @if (is_numeric($item['original']))
                                            @if (str_contains($key, 'percent') || str_contains($key, 'rate') || str_contains($key, 'ratio'))
                                                {{ number_format($item['original'], 2) }}{{ str_contains($key, 'ratio') ? '' : '%' }}
                                            @else
                                                ${{ number_format($item['original'], 2) }}
                                            @endif
                                        @else
                                            {{ $item['original'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="py-2 text-sm text-right">
                                        @if (is_numeric($item['current']))
                                            @if (str_contains($key, 'percent') || str_contains($key, 'rate') || str_contains($key, 'ratio'))
                                                {{ number_format($item['current'], 2) }}{{ str_contains($key, 'ratio') ? '' : '%' }}
                                            @else
                                                ${{ number_format($item['current'], 2) }}
                                            @endif
                                        @else
                                            {{ $item['current'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="py-2 text-sm text-right">
                                        @if ($item['changed'] && isset($item['delta']))
                                            <span class="{{ $item['delta'] > 0 ? 'text-green-600' : 'text-red-600' }}">
                                                {{ $item['delta'] > 0 ? '+' : '' }}{{ number_format($item['delta'], 2) }}
                                            </span>
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

        {{-- Tab 3: Actuals & Variance --}}
        @elseif ($activeTab === 'actuals')
            @if (! $card)
                <flux:callout variant="outline" icon="calculator">
                    <flux:callout.heading>{{ __('No Cost Card') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('No auto-generated cost card exists for this job. Use the Sync button on the Job Cost Cards page to generate one.') }}
                    </flux:callout.text>
                </flux:callout>
            @else
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 mb-6">
                    <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:heading size="sm">{{ __('Enter Actual Costs') }}</flux:heading>
                    </div>
                    <div class="p-4 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>{{ __('Material Cost') }}</flux:label>
                                <flux:input wire:model="actualMaterialCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Labor Cost') }}</flux:label>
                                <flux:input wire:model="actualLaborCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Subcontractor Cost') }}</flux:label>
                                <flux:input wire:model="actualSubcontractorCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Other Cost') }}</flux:label>
                                <flux:input wire:model="actualOtherCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                        </div>
                        <flux:field>
                            <flux:label>{{ __('Notes') }}</flux:label>
                            <flux:textarea wire:model="actualNotes" rows="2" :placeholder="__('Optional notes about actuals...')" />
                        </flux:field>
                        <div class="flex justify-end">
                            <flux:button wire:click="saveActuals" variant="primary" icon="check">
                                {{ __('Save Actuals') }}
                            </flux:button>
                        </div>
                    </div>
                </div>

                {{-- Variance Table --}}
                @if ($card->has_actuals)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:heading size="sm">{{ __('Projected vs Actual') }}</flux:heading>
                        </div>
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Projected') }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Actual') }}</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __('Variance') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @php
                                    $varianceRows = [
                                        [__('Materials'), (float) $card->material_cost + (float) $card->material_tax, $card->actual_material_cost],
                                        [__('Labor'), (float) $card->labor_cost + (float) $card->cleanup_cost, $card->actual_labor_cost],
                                        [__('Subcontractors'), (float) $card->subcontractor_cost, $card->actual_subcontractor_cost],
                                        [__('Other'), 0, $card->actual_other_cost],
                                    ];
                                @endphp
                                @foreach ($varianceRows as [$vLabel, $projected, $actual])
                                    @php
                                        $actualVal = $actual !== null ? (float) $actual : null;
                                        $variance = $actualVal !== null ? $projected - $actualVal : null;
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $vLabel }}</td>
                                        <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ number_format($projected, 2) }}</td>
                                        <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">
                                            {{ $actualVal !== null ? '$' . number_format($actualVal, 2) : '---' }}
                                        </td>
                                        <td class="px-4 py-2 text-sm text-right font-medium {{ $variance !== null ? ($variance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                            @if ($variance !== null)
                                                {{ $variance >= 0 ? '-' : '+' }}${{ number_format(abs($variance), 2) }}
                                            @else
                                                ---
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                <tr class="bg-zinc-50 dark:bg-zinc-800/50 font-semibold">
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ __('Total') }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->total_cogs, 2) }}</td>
                                    <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">
                                        {{ $card->actual_total_cost !== null ? '$' . number_format((float) $card->actual_total_cost, 2) : '---' }}
                                    </td>
                                    @php $totalVariance = $card->cost_variance; @endphp
                                    <td class="px-4 py-2 text-sm text-right font-bold {{ $totalVariance !== null ? ($totalVariance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                        @if ($totalVariance !== null)
                                            {{ $totalVariance >= 0 ? '-' : '+' }}${{ number_format(abs($totalVariance), 2) }}
                                            <span class="text-xs">({{ number_format(abs($card->cost_variance_percent), 1) }}%)</span>
                                        @else
                                            ---
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    @if ($card->actual_notes)
                        <div class="mt-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Notes') }}</flux:text>
                            <flux:text class="mt-1">{{ $card->actual_notes }}</flux:text>
                        </div>
                    @endif
                @endif
            @endif

        {{-- Tab 4: Payments --}}
        @elseif ($activeTab === 'payments')
            @if (! $card)
                <flux:callout variant="outline" icon="calculator">
                    <flux:callout.heading>{{ __('No Cost Card') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('No auto-generated cost card exists for this job. Use the Sync button on the Job Cost Cards page to generate one.') }}
                    </flux:callout.text>
                </flux:callout>
            @else
                {{-- Payment Summary --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total Invoiced') }}</div>
                        <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->total_invoiced, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Total Collected') }}</div>
                        <div class="text-xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format((float) $card->total_collected, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Balance Due') }}</div>
                        <div class="text-xl font-bold {{ (float) $card->balance_due > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                            ${{ number_format((float) $card->balance_due, 2) }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Collection %') }}</div>
                        <div class="text-xl font-bold {{ $card->collection_percent >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                            {{ number_format($card->collection_percent, 1) }}%
                        </div>
                    </div>
                </div>

                {{-- Sync Button --}}
                <div class="flex justify-end mb-4">
                    <flux:button wire:click="syncPayments" variant="ghost" icon="arrow-path" size="sm">
                        {{ __('Sync Payments') }}
                    </flux:button>
                </div>

                {{-- Invoice List --}}
                @if ($this->invoices->isEmpty())
                    <div class="text-center py-8 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="document-text" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                        <flux:text class="text-zinc-500">{{ __('No invoices yet.') }}</flux:text>
                    </div>
                @else
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:heading size="sm">{{ __('Invoices') }}</flux:heading>
                        </div>
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($this->invoices as $invoice)
                                <div class="flex items-center justify-between px-4 py-3" wire:key="inv-{{ $invoice->id }}">
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <flux:text class="font-medium text-sm">{{ $invoice->invoice_number }}</flux:text>
                                            @php
                                                $typeColor = match($invoice->type) {
                                                    'deposit' => 'blue',
                                                    'final' => 'green',
                                                    'change_order' => 'yellow',
                                                    default => 'zinc',
                                                };
                                            @endphp
                                            <flux:badge size="sm" color="{{ $typeColor }}">{{ \App\Models\Invoice::TYPES[$invoice->type] ?? $invoice->type }}</flux:badge>
                                        </div>
                                        <flux:text class="text-xs text-zinc-500 mt-0.5">
                                            {{ __('Due') }} {{ $invoice->due_date?->format('M j, Y') ?? 'N/A' }}
                                        </flux:text>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="text-right">
                                            <flux:text class="font-semibold text-sm">${{ number_format($invoice->total, 2) }}</flux:text>
                                            @if ((float) $invoice->amount_paid > 0 && ! $invoice->isPaid())
                                                <flux:text class="text-xs text-zinc-500">
                                                    {{ __('Paid:') }} ${{ number_format($invoice->amount_paid, 2) }}
                                                </flux:text>
                                            @endif
                                        </div>
                                        @php
                                            $statusColor = match($invoice->status) {
                                                'paid' => 'green',
                                                'sent' => 'blue',
                                                'partially_paid' => 'yellow',
                                                'overdue' => 'red',
                                                'void' => 'zinc',
                                                default => 'zinc',
                                            };
                                        @endphp
                                        <flux:badge size="sm" color="{{ $statusColor }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] ?? $invoice->status }}</flux:badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endif
        @endif
    </div>
</div>
