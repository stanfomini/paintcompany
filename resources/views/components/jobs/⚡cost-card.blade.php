<?php

use App\Models\Job;
use App\Models\JobCostCard;
use App\Services\JobCostCardService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Job $job;
    public ?JobCostCard $card = null;

    public string $activeTab = 'projected';

    // Actuals form fields
    public ?string $actualMaterialCost = null;
    public ?string $actualLaborCost = null;
    public ?string $actualSubcontractorCost = null;
    public ?string $actualOtherCost = null;
    public ?string $actualNotes = null;

    public function boot(): void
    {
        if (! auth()->user()?->can('financials.view_cost_breakdown')) {
            abort(403);
        }
    }

    public function mount(Job $job): void
    {
        $this->job = $job->load(['deal.customer', 'estimate', 'invoices.payments']);

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
    }

    public function saveActuals(): void
    {
        abort_unless(auth()->user()?->can('financials.view_cost_breakdown'), 403);

        if (! $this->card) {
            session()->flash('error', 'No cost card found for this job.');
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
        session()->flash('success', 'Actuals saved successfully.');
    }

    public function syncPayments(): void
    {
        abort_unless(auth()->user()?->can('financials.view_cost_breakdown'), 403);

        if (! $this->card) {
            session()->flash('error', 'No cost card found for this job.');
            return;
        }

        $service = new JobCostCardService;
        $service->syncPaymentSummary($this->job);

        $this->card->refresh();
        unset($this->invoices);
        session()->flash('success', 'Payment summary synced.');
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
    <div class="max-w-4xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('jobs.show', $job)" icon="arrow-left" wire:navigate>
                    Back to Job
                </flux:button>
                <div>
                    <flux:heading size="xl">Cost Card</flux:heading>
                    <flux:text class="text-zinc-500">{{ $job->title }} &mdash; {{ $job->job_number }}</flux:text>
                </div>
            </div>
            @if ($card && $card->scope_type)
                <flux:badge size="sm" color="{{ $card->scope_type === 'interior' ? 'blue' : 'amber' }}">
                    {{ ucfirst($card->scope_type) }}
                </flux:badge>
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

        @if (! $card)
            <flux:callout variant="outline" icon="calculator">
                <flux:callout.heading>No Cost Card</flux:callout.heading>
                <flux:callout.text>
                    No auto-generated cost card exists for this job. Use the Sync button on the Job Cost Cards page to generate one.
                </flux:callout.text>
            </flux:callout>
        @else
            {{-- Tab Buttons --}}
            <div class="mb-6 inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                @foreach (['projected' => 'Projected Costs', 'actuals' => 'Actuals & Variance', 'payments' => 'Payments'] as $tabKey => $tabLabel)
                    <button
                        wire:click="$set('activeTab', '{{ $tabKey }}')"
                        class="rounded-md px-4 py-2 text-sm font-medium transition-colors {{ $activeTab === $tabKey ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                    >
                        {{ $tabLabel }}
                    </button>
                @endforeach
            </div>

            {{-- Tab 1: Projected Costs --}}
            @if ($activeTab === 'projected')
                {{-- Summary Cards --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Price</div>
                        <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->price, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Total COGS</div>
                        <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->total_cogs, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Gross Profit %</div>
                        <div class="text-xl font-bold {{ $card->gross_profit_percent >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ number_format($card->gross_profit_percent, 1) }}%
                        </div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Net Profit %</div>
                        <div class="text-xl font-bold {{ $card->net_profit_percent >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ number_format($card->net_profit_percent, 1) }}%
                        </div>
                    </div>
                </div>

                {{-- COGS Decomposition --}}
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 mb-6">
                    <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:heading size="sm">COGS Decomposition</flux:heading>
                    </div>
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ([
                                ['Labor', $card->labor_cost],
                                ['Cleanup', $card->cleanup_cost],
                                ['Sundries', $card->sundries_cost],
                                ['Materials', $card->material_cost],
                                ['Material Tax', $card->material_tax],
                                ['Labor Tax', $card->labor_tax],
                                ['Add-ons', $card->addon_cost],
                                ['Items (Vendor)', $card->items_vendor_cost],
                                ['Warranty Reserve', $card->warranty_reserve],
                            ] as [$label, $amount])
                                @if ((float) $amount > 0)
                                    <tr>
                                        <td class="px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $label }}</td>
                                        <td class="px-4 py-2 text-sm text-right font-medium text-zinc-900 dark:text-zinc-100">${{ number_format((float) $amount, 2) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                            <tr class="bg-zinc-50 dark:bg-zinc-800/50 font-semibold">
                                <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Total COGS</td>
                                <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->total_cogs, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- Hours --}}
                @if ((float) $card->total_hours > 0)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 mb-6">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:heading size="sm">Hours</flux:heading>
                        </div>
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                <tr>
                                    <td class="px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">Labor Hours</td>
                                    <td class="px-4 py-2 text-sm text-right font-medium text-zinc-900 dark:text-zinc-100">{{ number_format((float) $card->labor_hours, 1) }}</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">Cleanup Hours</td>
                                    <td class="px-4 py-2 text-sm text-right font-medium text-zinc-900 dark:text-zinc-100">{{ number_format((float) $card->cleanup_hours, 1) }}</td>
                                </tr>
                                <tr class="bg-zinc-50 dark:bg-zinc-800/50 font-semibold">
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Total Hours</td>
                                    <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">{{ number_format((float) $card->total_hours, 1) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Allocations --}}
                @if ($card->cardAllocations && $card->cardAllocations->count() > 0)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 mb-6">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:heading size="sm">Allocations</flux:heading>
                        </div>
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($card->cardAllocations as $alloc)
                                    <tr wire:key="alloc-{{ $alloc->id }}">
                                        <td class="px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            {{ $alloc->name }}
                                            @if ($alloc->applied_tier)
                                                <span class="text-xs text-zinc-400 dark:text-zinc-500">({{ $alloc->applied_tier }})</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-sm text-right text-zinc-500 dark:text-zinc-400">{{ number_format((float) $alloc->value * 100, 2) }}%</td>
                                        <td class="px-4 py-2 text-sm text-right font-medium text-zinc-900 dark:text-zinc-100">${{ number_format((float) $alloc->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="bg-zinc-50 dark:bg-zinc-800/50 font-semibold">
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100" colspan="2">Total Allocations</td>
                                    <td class="px-4 py-2 text-sm text-right text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->allocation_total, 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endif

                {{-- Crew Bonus --}}
                @if ((float) $card->crew_bonus > 0)
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 mb-6 dark:border-emerald-800 dark:bg-emerald-950/30">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:icon name="star" class="w-5 h-5 text-emerald-500" />
                                <flux:text class="font-medium text-emerald-700 dark:text-emerald-400">Crew Bonus</flux:text>
                            </div>
                            <flux:text class="font-bold text-emerald-700 dark:text-emerald-400">${{ number_format((float) $card->crew_bonus, 2) }}</flux:text>
                        </div>
                    </div>
                @endif

                {{-- Settings Drift Warning --}}
                @if (count($this->settingsDrift) > 0)
                    <flux:callout variant="warning" icon="exclamation-triangle" class="mb-6">
                        <flux:callout.heading>Settings Changed Since Estimate</flux:callout.heading>
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

            {{-- Tab 2: Actuals & Variance --}}
            @elseif ($activeTab === 'actuals')
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 mb-6">
                    <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <flux:heading size="sm">Enter Actual Costs</flux:heading>
                    </div>
                    <div class="p-4 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Material Cost</flux:label>
                                <flux:input wire:model="actualMaterialCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Labor Cost</flux:label>
                                <flux:input wire:model="actualLaborCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Subcontractor Cost</flux:label>
                                <flux:input wire:model="actualSubcontractorCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Other Cost</flux:label>
                                <flux:input wire:model="actualOtherCost" type="number" step="0.01" min="0" placeholder="0.00" />
                            </flux:field>
                        </div>
                        <flux:field>
                            <flux:label>Notes</flux:label>
                            <flux:textarea wire:model="actualNotes" rows="2" placeholder="Optional notes about actuals..." />
                        </flux:field>
                        <div class="flex justify-end">
                            <flux:button wire:click="saveActuals" variant="primary" icon="check">
                                Save Actuals
                            </flux:button>
                        </div>
                    </div>
                </div>

                {{-- Variance Table --}}
                @if ($card->has_actuals)
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:heading size="sm">Projected vs Actual</flux:heading>
                        </div>
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Category</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Projected</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Actual</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Variance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @php
                                    $varianceRows = [
                                        ['Materials', (float) $card->material_cost + (float) $card->material_tax, $card->actual_material_cost],
                                        ['Labor', (float) $card->labor_cost + (float) $card->cleanup_cost, $card->actual_labor_cost],
                                        ['Subcontractors', (float) $card->subcontractor_cost, $card->actual_subcontractor_cost],
                                        ['Other', 0, $card->actual_other_cost],
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
                                    <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Total</td>
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
                            <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Notes</flux:text>
                            <flux:text class="mt-1">{{ $card->actual_notes }}</flux:text>
                        </div>
                    @endif
                @endif

            {{-- Tab 3: Payments --}}
            @elseif ($activeTab === 'payments')
                {{-- Payment Summary --}}
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 mb-6">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Total Invoiced</div>
                        <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $card->total_invoiced, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Total Collected</div>
                        <div class="text-xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format((float) $card->total_collected, 2) }}</div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Balance Due</div>
                        <div class="text-xl font-bold {{ (float) $card->balance_due > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                            ${{ number_format((float) $card->balance_due, 2) }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">Collection %</div>
                        <div class="text-xl font-bold {{ $card->collection_percent >= 100 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                            {{ number_format($card->collection_percent, 1) }}%
                        </div>
                    </div>
                </div>

                {{-- Sync Button --}}
                <div class="flex justify-end mb-4">
                    <flux:button wire:click="syncPayments" variant="ghost" icon="arrow-path" size="sm">
                        Sync Payments
                    </flux:button>
                </div>

                {{-- Invoice List --}}
                @if ($this->invoices->isEmpty())
                    <div class="text-center py-8 rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <flux:icon name="document-text" class="w-10 h-10 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                        <flux:text class="text-zinc-500">No invoices yet.</flux:text>
                    </div>
                @else
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <div class="px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                            <flux:heading size="sm">Invoices</flux:heading>
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
                                            Due {{ $invoice->due_date?->format('M j, Y') ?? 'N/A' }}
                                        </flux:text>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <div class="text-right">
                                            <flux:text class="font-semibold text-sm">${{ number_format($invoice->total, 2) }}</flux:text>
                                            @if ((float) $invoice->amount_paid > 0 && ! $invoice->isPaid())
                                                <flux:text class="text-xs text-zinc-500">
                                                    Paid: ${{ number_format($invoice->amount_paid, 2) }}
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
