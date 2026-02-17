<?php

use App\Models\Estimate;
use App\Services\AllocationEvaluationService;
use App\Services\EstimateCostBreakdownService;
use App\Services\EstimateTierPricingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    public string $activeTab = 'current';

    public float $whatIfMargin = 50;

    public bool $showWhatIf = false;

    public ?int $selectedPackageId = null;

    protected EstimateCostBreakdownService $service;

    public function boot(EstimateCostBreakdownService $service): void
    {
        if (! auth()->user()?->can('financials.view_cost_breakdown')) {
            abort(403);
        }

        $this->service = $service;
    }

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate->load(['areas.surfaces.productionRate', 'areas.addons', 'items', 'job.crews.crewProfile']);

        // Set initial what-if margin to current settings
        $currentSettings = $this->service->getCurrentSettings($estimate);
        $this->whatIfMargin = $currentSettings['margin_percent'];

        // Default to the active package (signed > selected > recommended)
        $activePackage = $this->service->getActivePackage($estimate);
        if ($activePackage) {
            $this->selectedPackageId = $activePackage->id;
        }
    }

    #[Computed]
    public function packages(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->estimate->packages()
            ->orderBy('sort_order')
            ->get();
    }

    public function selectPackage(int $packageId): void
    {
        // Verify the package belongs to this estimate
        $package = $this->estimate->packages()->where('id', $packageId)->first();
        if ($package) {
            $this->selectedPackageId = $packageId;
            unset($this->currentBreakdown);
        }
    }

    #[Computed]
    public function currentBreakdown(): array
    {
        // Build allocation context from estimate data so conditional allocations
        // (scope, season, sizing, etc.) are evaluated on the breakdown page
        $allocationContext = app(AllocationEvaluationService::class)
            ->buildContextFromEstimate($this->estimate);

        // If a specific package is selected, use it
        if ($this->selectedPackageId) {
            $package = $this->estimate->packages()->where('id', $this->selectedPackageId)->first();
            if ($package) {
                return $this->service->calculatePackageBreakdown($this->estimate, $package, $allocationContext);
            }
        }

        return $this->service->calculateBreakdown($this->estimate, $allocationContext);
    }

    #[Computed]
    public function comparison(): ?array
    {
        $snapshot = $this->estimate->settings_snapshot;

        if (empty($snapshot)) {
            return null;
        }

        $current = $this->service->getCurrentSettings($this->estimate);

        return $this->service->compareSnapshots($snapshot, $current);
    }

    #[Computed]
    public function whatIfBreakdown(): array
    {
        return $this->service->calculateWhatIfScenario($this->estimate, $this->whatIfMargin);
    }

    #[Computed]
    public function requiredMarginForTarget(): array
    {
        $breakdown = $this->currentBreakdown;
        $currentSettings = $this->service->getCurrentSettings($this->estimate);

        return $this->service->calculateRequiredMarginForTarget(
            $currentSettings['net_profit_margin_percent'],
            [
                'cogs' => $breakdown['cogs']['total'],
            ],
            $currentSettings
        );
    }

    /**
     * Get tier comparison data if the estimate uses paint tiers.
     *
     * @return array{good: float, better: float, best: float}|null
     */
    #[Computed]
    public function tierComparison(): ?array
    {
        if (! $this->estimate->hasPaintTiers()) {
            return null;
        }

        return app(EstimateTierPricingService::class)->calculateTierTotals($this->estimate);
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
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate>
                    {{ __('Back to Estimate') }}
                </flux:button>
                <div>
                    <flux:heading size="xl">{{ __('Cost Breakdown') }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ $estimate->estimate_number }} | {{ ucfirst($estimate->project_type ?? 'Interior') }}</flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <flux:button :href="route('estimates.breakdown.csv', $estimate)" icon="table-cells" variant="ghost">
                    Export CSV
                </flux:button>
                <flux:button :href="route('estimates.breakdown.pdf', $estimate)" target="_blank" icon="document-arrow-down" variant="ghost">
                    Export PDF
                </flux:button>
                <flux:button icon="printer" variant="ghost" onclick="window.print()">
                    Print
                </flux:button>
            </div>
        </div>

        {{-- Internal Only Warning --}}
        <flux:callout variant="warning" class="mb-6 print:hidden">
            <flux:icon name="eye-slash" class="w-5 h-5" />
            <div>
                <strong>Internal Only</strong> - This breakdown reveals true costs and profit margins. Never share with customers.
            </div>
        </flux:callout>

        {{-- Tier Comparison --}}
        @if ($this->tierComparison)
            <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Good / Better / Best Comparison') }}</h3>
                <div class="grid grid-cols-3 gap-4">
                    @foreach (['good' => 'Good', 'better' => 'Better', 'best' => 'Best'] as $tierKey => $tierLabel)
                        <div class="rounded-lg border border-zinc-200 p-3 text-center dark:border-zinc-700 {{ $tierKey === 'better' ? 'ring-2 ring-blue-500' : '' }}">
                            <div class="text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $tierLabel }}</div>
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

        @php $breakdown = $this->currentBreakdown; @endphp

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
                                            Signed
                                        </span>
                                    @elseif ($isSelected)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            Selected
                                        </span>
                                    @elseif ($isRecommended)
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                            Recommended
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-lg font-bold {{ $isActive ? 'text-blue-900 dark:text-blue-100' : 'text-zinc-900 dark:text-white' }}">
                                ${{ number_format($pkg->total, 2) }}
                            </div>
                            @if ($pkg->warranty_years > 0)
                                <div class="text-[11px] {{ $isActive ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}">
                                    {{ $pkg->warranty_years }}-Year Warranty
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

        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Customer Price</div>
                <div class="text-2xl font-bold text-zinc-900 dark:text-white">${{ number_format($breakdown['pricing']['customer_price'], 2) }}</div>
                <div class="text-xs text-zinc-400">COGS ratio: {{ number_format($breakdown['pricing']['margin_percent'], 1) }}%</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Gross Profit</div>
                <div class="text-2xl font-bold {{ $breakdown['gross_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    ${{ number_format($breakdown['gross_profit']['amount'], 2) }}
                </div>
                <div class="text-xs text-zinc-400">{{ number_format($breakdown['gross_profit']['percent'], 1) }}% of price</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Net Profit</div>
                <div class="text-2xl font-bold {{ $breakdown['net_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    ${{ number_format($breakdown['net_profit']['amount'], 2) }}
                </div>
                <div class="text-xs {{ $breakdown['net_profit']['meets_target'] ? 'text-green-500' : 'text-red-500' }}">
                    {{ number_format($breakdown['net_profit']['percent'], 1) }}% (target: {{ number_format($breakdown['net_profit']['target_percent'], 1) }}%)
                </div>
            </div>
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <div class="text-sm text-zinc-500 dark:text-zinc-400">Target Delta</div>
                <div class="text-2xl font-bold {{ $breakdown['net_profit']['delta_percent'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                    {{ $breakdown['net_profit']['delta_percent'] >= 0 ? '+' : '' }}{{ number_format($breakdown['net_profit']['delta_percent'], 1) }}%
                </div>
                <div class="text-xs text-zinc-400">
                    @if ($breakdown['net_profit']['meets_target'])
                        Meets target
                    @else
                        Below target
                    @endif
                </div>
            </div>
        </div>

        {{-- Settings Comparison Tab --}}
        @if ($this->comparison)
            <div class="flex gap-2 mb-6 print:hidden">
                <flux:button
                    wire:click="setActiveTab('current')"
                    variant="{{ $activeTab === 'current' ? 'primary' : 'ghost' }}"
                    size="sm"
                >
                    Breakdown
                </flux:button>
                <flux:button
                    wire:click="setActiveTab('comparison')"
                    variant="{{ $activeTab === 'comparison' ? 'primary' : 'ghost' }}"
                    size="sm"
                >
                    Settings Comparison
                </flux:button>
            </div>
        @endif

        {{-- Comparison View --}}
        @if ($activeTab === 'comparison' && $this->comparison)
            @php $comp = $this->comparison; @endphp
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <flux:heading size="sm" class="mb-4">Settings Comparison</flux:heading>

                @if ($comp['has_changes'])
                    <div class="mb-4 text-sm text-amber-600 dark:text-amber-400">
                        Settings have changed since this estimate was created.
                        @if ($comp['original_captured_at'])
                            <span class="text-zinc-500">(Captured: {{ \Carbon\Carbon::parse($comp['original_captured_at'])->format('M j, Y g:i A') }})</span>
                        @endif
                    </div>
                @else
                    <div class="mb-4 text-sm text-green-600 dark:text-green-400">
                        No significant changes in settings since estimate creation.
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead>
                            <tr>
                                <th class="py-2 text-left text-xs font-medium text-zinc-500 uppercase">Setting</th>
                                <th class="py-2 text-right text-xs font-medium text-zinc-500 uppercase">Original</th>
                                <th class="py-2 text-right text-xs font-medium text-zinc-500 uppercase">Current</th>
                                <th class="py-2 text-right text-xs font-medium text-zinc-500 uppercase">Change</th>
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
        @endif

        {{-- Debit/Credit Breakdown --}}
        @if ($activeTab !== 'comparison')
            <div class="space-y-6">
                {{-- DEBIT/CREDIT LEDGER STYLE --}}
                <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    <div class="bg-zinc-100 dark:bg-zinc-900 px-6 py-3 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">Financial Ledger</flux:heading>
                        <flux:text size="sm" class="text-zinc-500">Every dollar tracked to the cent</flux:text>
                    </div>

                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        {{-- REVENUE (CREDIT) --}}
                        <div class="px-6 py-4 bg-green-50 dark:bg-green-900/10">
                            <div class="flex justify-between items-center">
                                <div>
                                    <span class="text-xs font-medium text-green-600 dark:text-green-400 uppercase tracking-wider">Credit</span>
                                    <div class="text-lg font-semibold text-green-700 dark:text-green-300">Revenue (Customer Price)</div>
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
                                    <span class="text-xs font-medium text-red-600 dark:text-red-400 uppercase tracking-wider">Debit</span>
                                    <div class="text-lg font-semibold">Direct Costs (COGS)</div>
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
                                        Labor ({{ number_format($breakdown['totals']['labor_hours'], 1) }} hrs × ${{ number_format($effectiveRate, 2) }})
                                        <span class="text-xs text-zinc-400 block">Includes payroll burden ({{ number_format($breakdown['settings_used']['payroll_burden_multiplier'], 2) }}×)</span>
                                    </div>
                                    <span class="font-mono">${{ number_format($breakdown['cogs']['labor_cost'], 2) }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-zinc-600 dark:text-zinc-400">Cleanup ({{ number_format($breakdown['totals']['cleanup_hours'], 2) }} hrs × ${{ number_format($effectiveRate, 2) }})</span>
                                    <span class="font-mono">${{ number_format($breakdown['cogs']['cleanup_cost'], 2) }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-zinc-600 dark:text-zinc-400">Sundries</span>
                                    <span class="font-mono">${{ number_format($breakdown['cogs']['sundries_cost'], 2) }}</span>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <span class="text-zinc-600 dark:text-zinc-400">Materials</span>
                                    <span class="font-mono">${{ number_format($breakdown['cogs']['material_cost'], 2) }}</span>
                                </div>
                                @if (($breakdown['cogs']['material_tax'] ?? 0) > 0)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Material Tax ({{ number_format($breakdown['settings_used']['material_tax_rate'], 1) }}%)</span>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['material_tax'], 2) }}</span>
                                    </div>
                                @endif
                                @if ($breakdown['cogs']['labor_tax'] > 0)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Labor Tax</span>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['labor_tax'], 2) }}</span>
                                    </div>
                                @endif
                                @if ($breakdown['cogs']['addon_cost'] > 0)
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Nested Areas / Addons</span>
                                        <span class="font-mono">${{ number_format($breakdown['cogs']['addon_cost'], 2) }}</span>
                                    </div>
                                    @if ($breakdown['cogs']['addon_labor_hours'] > 0)
                                        <div class="flex justify-between text-xs text-zinc-500 pl-4">
                                            <span>Addon Labor ({{ number_format($breakdown['cogs']['addon_labor_hours'], 1) }} hrs)</span>
                                            <span class="font-mono">${{ number_format($breakdown['cogs']['addon_labor_cost'], 2) }}</span>
                                        </div>
                                    @endif
                                    @if ($breakdown['cogs']['addon_material_cost'] > 0)
                                        <div class="flex justify-between text-xs text-zinc-500 pl-4">
                                            <span>Addon Materials</span>
                                            <span class="font-mono">${{ number_format($breakdown['cogs']['addon_material_cost'], 2) }}</span>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>

                        {{-- ADD-ON SERVICES WITH VENDOR COST SPLIT --}}
                        @if (count($breakdown['items'] ?? []) > 0)
                            <div class="px-6 py-4">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <span class="text-xs font-medium text-orange-600 dark:text-orange-400 uppercase tracking-wider">Add-on Services</span>
                                        <div class="text-lg font-semibold">Line Items ({{ count($breakdown['items']) }})</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm text-zinc-500">Price: ${{ number_format($breakdown['cogs']['items_total'], 2) }}</div>
                                        <div class="text-sm text-zinc-500">Cost: ${{ number_format($breakdown['cogs']['items_vendor_cost'], 2) }}</div>
                                        <div class="font-bold text-green-600">Profit: ${{ number_format($breakdown['cogs']['items_profit'], 2) }}</div>
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
                                <div class="text-lg font-semibold">Gross Profit</div>
                                <div class="text-right">
                                    <div class="text-xl font-bold {{ $breakdown['gross_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                        ${{ number_format($breakdown['gross_profit']['amount'], 2) }}
                                    </div>
                                    <div class="text-sm text-zinc-500">{{ number_format($breakdown['gross_profit']['percent'], 1) }}% margin</div>
                                </div>
                            </div>
                        </div>

                        {{-- ALLOCATIONS (DEBIT) --}}
                        <div class="px-6 py-4">
                            <div class="flex justify-between items-center mb-4">
                                <div>
                                    <span class="text-xs font-medium text-red-600 dark:text-red-400 uppercase tracking-wider">Debit</span>
                                    <div class="text-lg font-semibold">Business Allocations</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-xl font-bold text-red-600 dark:text-red-400">(${{ number_format($breakdown['allocations']['total'], 2) }})</div>
                                    <div class="text-sm text-zinc-500">{{ number_format($breakdown['allocations']['total_percent'] ?? 0, 1) }}% of revenue</div>
                                </div>
                            </div>
                            <div class="space-y-2 pl-4 border-l-2 border-zinc-200 dark:border-zinc-700">
                                @if (isset($breakdown['allocations']['items']))
                                    {{-- Dynamic allocations --}}
                                    @foreach ($breakdown['allocations']['items'] as $alloc)
                                        <div class="flex justify-between text-sm">
                                            <span class="text-zinc-600 dark:text-zinc-400">
                                                {{ $alloc['name'] }}
                                                @if ($alloc['type'] === 'percent_of_revenue')
                                                    ({{ number_format($alloc['value'], 1) }}%)
                                                @elseif ($alloc['type'] === 'fixed_per_job')
                                                    <span class="text-xs text-zinc-500">(fixed/job)</span>
                                                @elseif ($alloc['type'] === 'per_labor_hour')
                                                    <span class="text-xs text-zinc-500">(${{ number_format($alloc['value'], 2) }}/hr)</span>
                                                @endif
                                            </span>
                                            <span class="font-mono">${{ number_format($alloc['amount'], 2) }}</span>
                                        </div>
                                    @endforeach
                                @else
                                    {{-- Legacy allocations --}}
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Marketing ({{ number_format($breakdown['allocations']['marketing']['rate'], 1) }}%)</span>
                                        <span class="font-mono">${{ number_format($breakdown['allocations']['marketing']['amount'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Sales ({{ number_format($breakdown['allocations']['sales']['rate'], 1) }}%)</span>
                                        <span class="font-mono">${{ number_format($breakdown['allocations']['sales']['amount'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">Production Mgmt ({{ number_format($breakdown['allocations']['production_management']['rate'], 1) }}%)</span>
                                        <span class="font-mono">${{ number_format($breakdown['allocations']['production_management']['amount'], 2) }}</span>
                                    </div>
                                @endif
                            </div>
                            @if (isset($breakdown['allocations']['overhead']) && !($breakdown['allocations']['overhead']['informational'] ?? false) === false)
                                <div class="mt-3 pt-3 border-t border-dashed border-zinc-200 dark:border-zinc-700">
                                    <div class="flex justify-between text-sm text-zinc-500">
                                        <span>General Overhead ({{ number_format($breakdown['allocations']['overhead']['rate'], 1) }}%) <span class="text-xs italic">informational only</span></span>
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
                                        <span class="text-xs font-medium text-amber-600 dark:text-amber-400 uppercase tracking-wider">Conditional Bonus</span>
                                        <div class="text-lg font-semibold">Crew Lead Bonus</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-xl font-bold text-green-600 dark:text-green-400">${{ number_format($crewBonus['bonus_amount'], 2) }}</div>
                                        <div class="text-xs text-zinc-500">{{ $crewBonus['crew_name'] }} ({{ number_format($crewBonus['bonus_percent'], 1) }}%)</div>
                                    </div>
                                </div>
                                <div class="p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800">
                                    <div class="text-xs font-medium text-amber-700 dark:text-amber-300 mb-2">How to Earn This Bonus:</div>
                                    <ul class="text-xs text-zinc-600 dark:text-zinc-400 space-y-1 list-disc list-inside">
                                        <li>Actively use and engage with the job software throughout the project</li>
                                        <li>Be active in the Job Chat - communicate with the team and customer</li>
                                        <li>Upload, view, and manage team and customer photos and communications</li>
                                        <li>Participate in Job Prep to help build a plan the team can follow</li>
                                        <li>Follow the plan as outlined during prep</li>
                                        <li>Do not promote personal business on the job site</li>
                                        <li>Dress appropriately and maintain a professional appearance</li>
                                        <li>Ensure all touchups are completed before job closeout</li>
                                    </ul>
                                </div>
                            </div>
                        @endif

                        {{-- NET PROFIT --}}
                        <div class="px-6 py-4 {{ $breakdown['net_profit']['is_profitable'] ? 'bg-green-100 dark:bg-green-900/30' : 'bg-red-100 dark:bg-red-900/30' }}">
                            <div class="flex justify-between items-center">
                                <div>
                                    <span class="text-xs font-medium uppercase tracking-wider {{ $breakdown['net_profit']['is_profitable'] ? 'text-green-600' : 'text-red-600' }}">Net Result</span>
                                    <div class="text-lg font-semibold">Net Profit</div>
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
                                        <span class="text-xs font-medium text-zinc-500 uppercase tracking-wider">If Bonus Paid</span>
                                        <div class="text-lg font-semibold">Net Profit After Bonus</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-bold {{ ($breakdown['net_profit']['after_bonus_amount'] ?? 0) >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                            ${{ number_format($breakdown['net_profit']['after_bonus_amount'], 2) }}
                                        </div>
                                        <div class="text-sm text-zinc-500">
                                            {{ number_format($breakdown['net_profit']['after_bonus_percent'], 1) }}% margin
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
                            $isBalanced = $difference < 0.02; // Allow for 1 cent rounding
                        @endphp
                        <div class="px-6 py-3 bg-zinc-800 dark:bg-zinc-950 text-white">
                            <div class="flex justify-between items-center text-sm">
                                <div class="flex items-center gap-2">
                                    @if ($isBalanced)
                                        <svg class="w-5 h-5 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                        </svg>
                                        <span class="text-green-400">Verification Passed</span>
                                    @else
                                        <svg class="w-5 h-5 text-red-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                        </svg>
                                        <span class="text-red-400">Verification Failed (off by ${{ number_format($difference, 2) }})</span>
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
                    <flux:heading size="sm" class="mb-4">Per-Area Detail</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3">
                        Numbers from each area's model - the same source as the estimate page.
                    </flux:text>
                    @php $hasTierPricing = !empty($breakdown['areas'][0]['tier_pricing'] ?? null); @endphp
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-zinc-500 uppercase">Area</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase">Hours</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase">COGS</th>
                                    <th class="px-4 py-3 text-center text-xs font-medium text-zinc-500 uppercase">Margin</th>
                                    @if ($hasTierPricing)
                                        <th class="px-4 py-3 text-right text-xs font-medium text-green-600 uppercase">Good</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-blue-600 uppercase">Better</th>
                                        <th class="px-4 py-3 text-right text-xs font-medium text-purple-600 uppercase">Best</th>
                                    @else
                                        <th class="px-4 py-3 text-right text-xs font-medium text-zinc-500 uppercase">Customer Price</th>
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
                                                <span class="ml-1 px-1.5 py-0.5 text-xs bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300 rounded">Fixed</span>
                                            @endif
                                            @if ($area['has_custom_pricing'] ?? false)
                                                <span class="ml-1 px-1.5 py-0.5 text-xs bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded">Custom</span>
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
                                        <td class="px-4 py-3 text-sm font-medium">Line Items ({{ count($breakdown['items'] ?? []) }})</td>
                                        <td class="px-4 py-3 text-sm text-right">-</td>
                                        <td class="px-4 py-3 text-sm text-right">${{ number_format($breakdown['cogs']['items_vendor_cost'], 2) }}</td>
                                        <td class="px-4 py-3 text-sm text-center">-</td>
                                        <td class="px-4 py-3 text-sm text-right font-medium">${{ number_format($breakdown['items_total'], 2) }}</td>
                                    </tr>
                                @endif
                            </tbody>
                            <tfoot>
                                <tr class="bg-zinc-50 dark:bg-zinc-800 font-bold">
                                    <td class="px-4 py-3 text-sm">TOTALS</td>
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
                    $perService = app(\App\Services\EstimateCostBreakdownService::class)->getPerServiceBreakdown($estimate);
                @endphp

                @if (count($perService) > 1)
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-4">Breakdown by Service</flux:heading>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            @foreach ($perService as $slug => $service)
                                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-4" wire:key="service-{{ $slug }}">
                                    <div class="flex items-center gap-2 mb-3">
                                        <flux:badge size="sm" color="{{ $service['category_color'] }}">
                                            {{ $service['category_name'] }}
                                        </flux:badge>
                                        <span class="text-sm text-zinc-500">{{ $service['margin_percent'] }}% margin</span>
                                    </div>
                                    <div class="space-y-1 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">COGS</span>
                                            <span class="font-medium font-mono">${{ number_format($service['cogs'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">Price</span>
                                            <span class="font-medium font-mono">${{ number_format($service['price'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between border-t border-zinc-100 dark:border-zinc-800 pt-1 mt-1">
                                            <span class="text-zinc-600 dark:text-zinc-400">Gross Profit</span>
                                            <span class="font-medium font-mono text-green-600">${{ number_format($service['price'] - $service['cogs'], 2) }}</span>
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
                        <flux:heading size="sm" class="mb-4 text-blue-800 dark:text-blue-200">Settings Drift Check</flux:heading>
                        <flux:text size="sm" class="text-blue-700 dark:text-blue-300 mb-3">
                            Compares the package price (what the customer sees) against what the current settings would calculate. A difference means settings have changed since the package was generated.
                        </flux:text>
                        <div class="space-y-2">
                            <div class="flex justify-between text-sm">
                                <span>Package Price (customer sees)</span>
                                <span class="font-medium">${{ number_format($breakdown['reconciliation']['stored_subtotal'], 2) }}</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span>Current Settings Would Price At</span>
                                <span class="font-medium">${{ number_format($breakdown['reconciliation']['calculated_price'], 2) }}</span>
                            </div>
                            <div class="flex justify-between text-sm font-bold pt-2 border-t border-blue-200 dark:border-blue-800">
                                <span>Drift</span>
                                <span class="{{ $breakdown['reconciliation']['difference'] > 0 ? 'text-amber-600' : 'text-amber-600' }}">
                                    {{ $breakdown['reconciliation']['difference'] > 0 ? '+' : '' }}${{ number_format($breakdown['reconciliation']['difference'], 2) }}
                                </span>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Settings Reference --}}
        <div class="mt-6" x-data="{ expanded: false }">
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                <div class="flex items-center justify-between p-4 cursor-pointer bg-zinc-50 dark:bg-zinc-800/50" @click="expanded = !expanded">
                    <flux:heading size="sm">Settings Used</flux:heading>
                    <svg x-show="!expanded" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    <svg x-show="expanded" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                </div>
                <div x-show="expanded" x-collapse class="border-t border-zinc-200 dark:border-zinc-700 p-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                        <div>
                            <span class="text-zinc-500">Scope:</span>
                            <span class="font-medium ml-1">{{ ucfirst($breakdown['settings_used']['scope']) }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Hourly Wage:</span>
                            <span class="font-medium ml-1">${{ number_format($breakdown['settings_used']['hourly_rate_override'] ?? $breakdown['settings_used']['hourly_wage'], 2) }}</span>
                            @if ($breakdown['settings_used']['hourly_rate_override'] ?? null)
                                <span class="text-xs text-amber-600 dark:text-amber-400">(override)</span>
                            @endif
                        </div>
                        <div>
                            <span class="text-zinc-500">Payroll Burden:</span>
                            <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['payroll_burden_multiplier'], 2) }}x</span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Margin:</span>
                            <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['margin_percent'], 1) }}%</span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Cleanup Ratio:</span>
                            <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['cleanup_hours_ratio'], 0) }}:1</span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Sundries/Hr:</span>
                            <span class="font-medium ml-1">${{ number_format($breakdown['settings_used']['sundries_cost_per_hour'], 2) }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Material Tax:</span>
                            <span class="font-medium ml-1">{{ number_format($breakdown['settings_used']['material_tax_rate'], 1) }}%</span>
                        </div>
                        <div>
                            <span class="text-zinc-500">Target Net Margin:</span>
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
                    <flux:heading size="sm">What-If: Margin Rate</flux:heading>
                    <svg x-show="!showWhatIf" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    <svg x-show="showWhatIf" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                </div>
                <div x-show="showWhatIf" x-collapse class="border-t border-zinc-200 dark:border-zinc-700 p-4">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">
                                Margin Rate: {{ number_format($whatIfMargin, 1) }}%
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

                        @php $whatIf = $this->whatIfBreakdown; @endphp
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-zinc-50 dark:bg-zinc-900 rounded-lg">
                            <div>
                                <div class="text-xs text-zinc-500">Customer Price</div>
                                <div class="text-lg font-bold">${{ number_format($whatIf['pricing']['customer_price'], 2) }}</div>
                            </div>
                            <div>
                                <div class="text-xs text-zinc-500">Gross Profit</div>
                                <div class="text-lg font-bold {{ $whatIf['gross_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    ${{ number_format($whatIf['gross_profit']['amount'], 2) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-zinc-500">Net Profit</div>
                                <div class="text-lg font-bold {{ $whatIf['net_profit']['amount'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    ${{ number_format($whatIf['net_profit']['amount'], 2) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-zinc-500">Net Margin</div>
                                <div class="text-lg font-bold {{ $whatIf['net_profit']['meets_target'] ? 'text-green-600' : 'text-amber-600' }}">
                                    {{ number_format($whatIf['net_profit']['percent'], 1) }}%
                                </div>
                            </div>
                        </div>

                        @php $required = $this->requiredMarginForTarget; @endphp
                        @if ($required['is_achievable'])
                            <div class="text-sm text-zinc-600 dark:text-zinc-400 bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg">
                                <strong>To achieve {{ number_format($breakdown['net_profit']['target_percent'], 1) }}% net margin:</strong>
                                Set margin rate to <strong>{{ number_format($required['required_margin_percent'], 1) }}%</strong>
                                (Customer price: ${{ number_format($required['required_customer_price'], 2) }})
                            </div>
                        @else
                            <div class="text-sm text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 p-3 rounded-lg">
                                {{ $required['message'] }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
