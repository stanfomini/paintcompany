<?php

use App\Models\JobCostCard;
use App\Services\AllocationEvaluationService;
use App\Services\EstimateCostBreakdownService;
use App\Services\JobCostCardService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public JobCostCard $card;

    /** @var array|null The live breakdown from EstimateCostBreakdownService */
    public ?array $breakdown = null;

    // Actual form fields (granular COGS)
    public ?string $actualLaborCost = null;
    public ?string $actualMaterialCost = null;
    public ?string $actualCleanupCost = null;
    public ?string $actualSundriesCost = null;
    public ?string $actualMaterialTax = null;
    public ?string $actualLaborTax = null;
    public ?string $actualAddonCost = null;
    public ?string $actualItemsVendorCost = null;
    public ?string $actualWarrantyReserve = null;
    public ?string $actualCrewBonus = null;
    public ?string $actualNotes = null;

    // Allocation actuals keyed by index
    /** @var array<int, string|null> */
    public array $allocationActuals = [];

    public string $saveMessage = '';
    public string $journalMessage = '';
    public bool $showProjectedConfirm = false;

    public function mount(JobCostCard $card): void
    {
        $this->card = $card;

        // Populate actual form fields from existing actuals
        $this->actualLaborCost = $card->actual_labor_cost !== null ? (string) $card->actual_labor_cost : null;
        $this->actualMaterialCost = $card->actual_material_cost !== null ? (string) $card->actual_material_cost : null;
        $this->actualCleanupCost = $card->actual_cleanup_cost !== null ? (string) $card->actual_cleanup_cost : null;
        $this->actualSundriesCost = $card->actual_sundries_cost !== null ? (string) $card->actual_sundries_cost : null;
        $this->actualMaterialTax = $card->actual_material_tax !== null ? (string) $card->actual_material_tax : null;
        $this->actualLaborTax = $card->actual_labor_tax !== null ? (string) $card->actual_labor_tax : null;
        $this->actualAddonCost = $card->actual_addon_cost !== null ? (string) $card->actual_addon_cost : null;
        $this->actualItemsVendorCost = $card->actual_items_vendor_cost !== null ? (string) $card->actual_items_vendor_cost : null;
        $this->actualWarrantyReserve = $card->actual_warranty_reserve !== null ? (string) $card->actual_warranty_reserve : null;
        $this->actualCrewBonus = $card->actual_crew_bonus !== null ? (string) $card->actual_crew_bonus : null;
        $this->actualNotes = $card->actual_notes;

        // Load the live breakdown from the service (source of truth)
        $this->loadBreakdown();
    }

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    protected function loadBreakdown(): void
    {
        if (! $this->card->estimate_id) {
            return;
        }

        try {
            $estimate = $this->card->estimate;
            if (! $estimate) {
                return;
            }

            $estimate->loadMissing(['areas.surfaces.productionRate', 'areas.addons', 'items', 'packages.items.itemable']);

            // Build allocation context from the card's job data (payment methods, scope, etc.)
            $allocationContext = app(AllocationEvaluationService::class)->buildContext($this->card);

            $service = new EstimateCostBreakdownService;
            $this->breakdown = $service->calculateBreakdown($estimate, $allocationContext);
        } catch (\Throwable $e) {
            report($e);
            $this->breakdown = null;
        }
    }

    public function saveActuals(): void
    {
        $this->authorize('business_plan.manage');

        $service = new JobCostCardService;

        $service->updateActuals($this->card, [
            'actual_labor_cost' => $this->actualLaborCost,
            'actual_material_cost' => $this->actualMaterialCost,
            'actual_cleanup_cost' => $this->actualCleanupCost,
            'actual_sundries_cost' => $this->actualSundriesCost,
            'actual_material_tax' => $this->actualMaterialTax,
            'actual_labor_tax' => $this->actualLaborTax,
            'actual_addon_cost' => $this->actualAddonCost,
            'actual_items_vendor_cost' => $this->actualItemsVendorCost,
            'actual_warranty_reserve' => $this->actualWarrantyReserve,
            'actual_crew_bonus' => $this->actualCrewBonus,
            'actual_notes' => $this->actualNotes,
        ]);

        // Save allocation actuals if cardAllocations exist
        $this->card->load('cardAllocations');
        if ($this->card->cardAllocations->isNotEmpty()) {
            $allocActuals = [];
            foreach ($this->allocationActuals as $allocId => $amount) {
                $allocActuals[(int) $allocId] = $amount;
            }
            $service->updateAllocationActuals($this->card, $allocActuals);
        }

        $this->card->refresh();
        $this->saveMessage = 'Actuals saved.';
        $this->journalMessage = '';

        $this->dispatch('actuals-saved');
    }

    public function postToJournal(): void
    {
        $this->authorize('business_plan.manage');

        if ($this->card->journal_entry_id) {
            $this->journalMessage = 'Journal entry already exists for this card.';
            return;
        }

        if (! $this->card->has_actuals) {
            $this->showProjectedConfirm = true;

            return;
        }

        $this->createJournalEntry();
    }

    public function confirmUseProjected(): void
    {
        $this->authorize('business_plan.manage');

        $this->showProjectedConfirm = false;

        // Copy projected values to actuals
        $projected = [
            'actual_labor_cost' => $this->card->labor_cost ?? 0,
            'actual_material_cost' => $this->card->material_cost ?? 0,
            'actual_cleanup_cost' => $this->card->cleanup_cost ?? 0,
            'actual_sundries_cost' => $this->card->sundries_cost ?? 0,
            'actual_material_tax' => $this->card->material_tax ?? 0,
            'actual_labor_tax' => $this->card->labor_tax ?? 0,
            'actual_addon_cost' => $this->card->addon_cost ?? 0,
            'actual_items_vendor_cost' => $this->card->items_vendor_cost ?? 0,
            'actual_warranty_reserve' => $this->card->warranty_reserve ?? 0,
            'actual_crew_bonus' => $this->card->crew_bonus ?? 0,
            'actual_subcontractor_cost' => $this->card->subcontractor_cost ?? 0,
            'actual_other_cost' => 0,
        ];

        $this->card->update($projected);
        $this->card->update(['actual_total_cost' => $this->card->actual_cogs]);

        // Also copy allocation totals
        $this->card->update(['actual_allocation_total' => $this->card->allocation_total]);
        foreach ($this->card->cardAllocations as $i => $alloc) {
            $alloc->update(['actual_amount' => $alloc->amount]);
        }

        // Sync form fields
        $this->actualLaborCost = (string) ($this->card->actual_labor_cost ?? 0);
        $this->actualMaterialCost = (string) ($this->card->actual_material_cost ?? 0);
        $this->actualCleanupCost = (string) ($this->card->actual_cleanup_cost ?? 0);
        $this->actualSundriesCost = (string) ($this->card->actual_sundries_cost ?? 0);
        $this->actualMaterialTax = (string) ($this->card->actual_material_tax ?? 0);
        $this->actualLaborTax = (string) ($this->card->actual_labor_tax ?? 0);
        $this->actualAddonCost = (string) ($this->card->actual_addon_cost ?? 0);
        $this->actualItemsVendorCost = (string) ($this->card->actual_items_vendor_cost ?? 0);
        $this->actualWarrantyReserve = (string) ($this->card->actual_warranty_reserve ?? 0);
        $this->actualCrewBonus = (string) ($this->card->actual_crew_bonus ?? 0);

        $this->card->refresh();
        $this->createJournalEntry();
    }

    public function cancelProjectedConfirm(): void
    {
        $this->showProjectedConfirm = false;
    }

    protected function createJournalEntry(): void
    {
        $service = new JobCostCardService;
        $entry = $service->createJournalEntryFromActuals($this->card, Auth::id());

        $this->card->refresh();
        $this->journalMessage = "Draft journal entry {$entry->entry_number} created.";
        $this->saveMessage = '';
    }

    public function recreateJournalEntry(): void
    {
        $this->authorize('business_plan.manage');

        $oldEntry = $this->card->journalEntry;

        if ($oldEntry) {
            $accountingService = new \App\Services\AccountingService;
            $accountingService->voidEntry($oldEntry);
        }

        $this->card->update(['journal_entry_id' => null]);
        $this->card->refresh();

        $this->createJournalEntry();
        $this->journalMessage = "Old entry voided. New draft journal entry created.";
    }

    protected function variance(?string $actual, float $projected): ?float
    {
        if ($actual === null || $actual === '') {
            return null;
        }

        return round($projected - (float) $actual, 2);
    }

    protected function actualCogsTotal(): float
    {
        $fields = [
            $this->actualLaborCost, $this->actualMaterialCost, $this->actualCleanupCost,
            $this->actualSundriesCost, $this->actualMaterialTax, $this->actualLaborTax,
            $this->actualAddonCost, $this->actualItemsVendorCost, $this->actualWarrantyReserve,
        ];

        $total = 0.0;
        foreach ($fields as $val) {
            $total += ($val !== null && $val !== '') ? (float) $val : 0;
        }

        return round($total, 2);
    }
};
?>

<div class="border-t border-zinc-200 bg-zinc-50/50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800/30" wire:key="detail-{{ $card->id }}">
  <div class="max-w-3xl">
    {{-- Header --}}
    <div class="mb-4 flex items-center justify-between">
        <div class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
            {{ $card->client_name }}
        </div>
        <div class="flex items-center gap-2">
            @if ($card->scope_type)
                <flux:badge size="sm" :color="$card->scope_type === 'exterior' ? 'amber' : 'blue'">
                    {{ ucfirst($card->scope_type) }}
                </flux:badge>
            @endif
            @if ($card->job_id)
                <a href="{{ route('jobs.cost-card', $card->job_id) }}" class="text-xs text-blue-600 hover:underline dark:text-blue-400" wire:navigate>
                    View Full Cost Card &rarr;
                </a>
            @endif
        </div>
    </div>

    {{-- Messages --}}
    @if ($saveMessage)
        <div class="mb-3 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400">
            <flux:icon name="check-circle" variant="mini" class="shrink-0" />
            {{ $saveMessage }}
        </div>
    @endif
    @if ($journalMessage)
        <div class="mb-3 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-400">
            <flux:icon name="information-circle" variant="mini" class="shrink-0" />
            {{ $journalMessage }}
        </div>
    @endif

    @if ($showProjectedConfirm)
        <div class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
            <div class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                <div class="flex-1">
                    <p class="text-sm font-medium text-amber-800 dark:text-amber-300">No actuals were entered</p>
                    <p class="mt-1 text-sm text-amber-700 dark:text-amber-400">Use projected values as actuals and post to journal?</p>
                    <div class="mt-3 flex items-center gap-2">
                        <flux:button variant="primary" size="sm" wire:click="confirmUseProjected">
                            Yes, use projected values
                        </flux:button>
                        <flux:button variant="ghost" size="sm" wire:click="cancelProjectedConfirm">
                            Cancel
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if (! $breakdown)
        <div class="rounded-lg border border-zinc-200 bg-white p-4 text-sm text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">
            No estimate linked &mdash; live breakdown not available for manual cards.
        </div>
    @else
        @php
            $bd = $breakdown;
            $customerPrice = $bd['pricing']['customer_price'];
            $effectiveRate = $bd['settings_used']['hourly_wage'] * $bd['settings_used']['payroll_burden_multiplier'];
            $projectedCogsTotal = $bd['cogs']['total'];
            $projectedGP = $bd['gross_profit']['amount'];
            $projectedAllocTotal = $bd['allocations']['total'];
            $projectedNet = $bd['net_profit']['amount'];
            $actualCogsTotal = $this->actualCogsTotal();
            $hasActuals = $actualCogsTotal > 0;
        @endphp

        {{-- Customer Price (Revenue) --}}
        <div class="mb-4 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2.5 dark:border-emerald-800 dark:bg-emerald-950/20">
            <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-300">Customer Price (Revenue)</span>
            <span class="text-lg font-bold text-emerald-700 dark:text-emerald-300">${{ number_format($customerPrice, 2) }}</span>
        </div>

        {{-- DROPDOWN 1: Direct Costs (COGS) --}}
        <div x-data="{ open: false }" class="mb-3">
            <button
                @click="open = !open"
                class="flex w-full items-center justify-between rounded-lg border border-zinc-200 bg-white px-4 py-3 text-left transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
            >
                <div class="flex items-center gap-3">
                    <span class="text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">Debit</span>
                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Direct Costs (COGS)</span>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-sm font-bold text-red-600 dark:text-red-400">(${{ number_format($projectedCogsTotal, 2) }})</span>
                    <svg x-show="!open" class="h-4 w-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    <svg x-show="open" class="h-4 w-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                </div>
            </button>

            <div x-show="open" x-collapse class="mt-1 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-100 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium text-zinc-600 dark:text-zinc-400">Cost Line</th>
                            <th class="px-4 py-2 text-right font-medium text-zinc-600 dark:text-zinc-400">Projected</th>
                            <th class="px-4 py-2 text-right font-medium text-zinc-600 dark:text-zinc-400">Actual</th>
                            <th class="px-4 py-2 text-right font-medium text-zinc-600 dark:text-zinc-400">Variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                        {{-- Labor --}}
                        @php $v = $this->variance($actualLaborCost, $bd['cogs']['labor_cost']); @endphp
                        <tr>
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                Labor
                                <span class="text-xs text-zinc-400">({{ number_format($bd['totals']['labor_hours'], 1) }} hrs &times; ${{ number_format($effectiveRate, 2) }})</span>
                                <span class="block text-xs text-zinc-400">Includes payroll burden ({{ number_format($bd['settings_used']['payroll_burden_multiplier'], 2) }}&times;)</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['labor_cost'], 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($this->canManage)
                                    <input type="number" step="0.01" wire:model.blur="actualLaborCost" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                @elseif ($actualLaborCost !== null && $actualLaborCost !== '')
                                    <span class="font-mono">${{ number_format((float) $actualLaborCost, 2) }}</span>
                                @else
                                    <span class="text-zinc-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                            </td>
                        </tr>

                        {{-- Cleanup --}}
                        @php $v = $this->variance($actualCleanupCost, $bd['cogs']['cleanup_cost']); @endphp
                        <tr>
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                Cleanup
                                <span class="text-xs text-zinc-400">({{ number_format($bd['totals']['cleanup_hours'], 2) }} hrs &times; ${{ number_format($effectiveRate, 2) }})</span>
                            </td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['cleanup_cost'], 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($this->canManage)
                                    <input type="number" step="0.01" wire:model.blur="actualCleanupCost" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                @elseif ($actualCleanupCost !== null && $actualCleanupCost !== '')
                                    <span class="font-mono">${{ number_format((float) $actualCleanupCost, 2) }}</span>
                                @else
                                    <span class="text-zinc-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                            </td>
                        </tr>

                        {{-- Sundries --}}
                        @php $v = $this->variance($actualSundriesCost, $bd['cogs']['sundries_cost']); @endphp
                        <tr>
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">Sundries</td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['sundries_cost'], 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($this->canManage)
                                    <input type="number" step="0.01" wire:model.blur="actualSundriesCost" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                @elseif ($actualSundriesCost !== null && $actualSundriesCost !== '')
                                    <span class="font-mono">${{ number_format((float) $actualSundriesCost, 2) }}</span>
                                @else
                                    <span class="text-zinc-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                            </td>
                        </tr>

                        {{-- Materials --}}
                        @php $v = $this->variance($actualMaterialCost, $bd['cogs']['material_cost']); @endphp
                        <tr>
                            <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">Materials</td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['material_cost'], 2) }}</td>
                            <td class="px-4 py-2 text-right">
                                @if ($this->canManage)
                                    <input type="number" step="0.01" wire:model.blur="actualMaterialCost" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                @elseif ($actualMaterialCost !== null && $actualMaterialCost !== '')
                                    <span class="font-mono">${{ number_format((float) $actualMaterialCost, 2) }}</span>
                                @else
                                    <span class="text-zinc-400">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                            </td>
                        </tr>

                        {{-- Material Tax --}}
                        @if (($bd['cogs']['material_tax'] ?? 0) > 0)
                            @php $v = $this->variance($actualMaterialTax, $bd['cogs']['material_tax']); @endphp
                            <tr>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                    Material Tax
                                    <span class="text-xs text-zinc-400">({{ number_format($bd['settings_used']['material_tax_rate'], 1) }}%)</span>
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['material_tax'], 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($this->canManage)
                                        <input type="number" step="0.01" wire:model.blur="actualMaterialTax" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                    @elseif ($actualMaterialTax !== null && $actualMaterialTax !== '')
                                        <span class="font-mono">${{ number_format((float) $actualMaterialTax, 2) }}</span>
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                    @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                                </td>
                            </tr>
                        @endif

                        {{-- Labor Tax --}}
                        @if ($bd['cogs']['labor_tax'] > 0)
                            @php $v = $this->variance($actualLaborTax, $bd['cogs']['labor_tax']); @endphp
                            <tr>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">Labor Tax</td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['labor_tax'], 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($this->canManage)
                                        <input type="number" step="0.01" wire:model.blur="actualLaborTax" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                    @elseif ($actualLaborTax !== null && $actualLaborTax !== '')
                                        <span class="font-mono">${{ number_format((float) $actualLaborTax, 2) }}</span>
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                    @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                                </td>
                            </tr>
                        @endif

                        {{-- Nested Areas / Addons --}}
                        @if ($bd['cogs']['addon_cost'] > 0)
                            @php $v = $this->variance($actualAddonCost, $bd['cogs']['addon_cost']); @endphp
                            <tr>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">Nested Areas / Addons</td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['addon_cost'], 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($this->canManage)
                                        <input type="number" step="0.01" wire:model.blur="actualAddonCost" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                    @elseif ($actualAddonCost !== null && $actualAddonCost !== '')
                                        <span class="font-mono">${{ number_format((float) $actualAddonCost, 2) }}</span>
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                    @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                                </td>
                            </tr>
                        @endif

                        {{-- Warranty Reserve --}}
                        @if (($bd['cogs']['warranty_price'] ?? 0) > 0)
                            @php $v = $this->variance($actualWarrantyReserve, $bd['cogs']['warranty_price']); @endphp
                            <tr>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">Warranty Reserve</td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['warranty_price'], 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($this->canManage)
                                        <input type="number" step="0.01" wire:model.blur="actualWarrantyReserve" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                    @elseif ($actualWarrantyReserve !== null && $actualWarrantyReserve !== '')
                                        <span class="font-mono">${{ number_format((float) $actualWarrantyReserve, 2) }}</span>
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                    @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                                </td>
                            </tr>
                        @endif

                        {{-- Line Items (Vendor Cost) --}}
                        @if (($bd['cogs']['items_vendor_cost'] ?? 0) > 0)
                            @php $v = $this->variance($actualItemsVendorCost, $bd['cogs']['items_vendor_cost']); @endphp
                            <tr>
                                <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                    Line Items (Vendor Cost)
                                    <span class="text-xs text-zinc-400">{{ count($bd['items'] ?? []) }} item(s)</span>
                                </td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">${{ number_format($bd['cogs']['items_vendor_cost'], 2) }}</td>
                                <td class="px-4 py-2 text-right">
                                    @if ($this->canManage)
                                        <input type="number" step="0.01" wire:model.blur="actualItemsVendorCost" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                    @elseif ($actualItemsVendorCost !== null && $actualItemsVendorCost !== '')
                                        <span class="font-mono">${{ number_format((float) $actualItemsVendorCost, 2) }}</span>
                                    @else
                                        <span class="text-zinc-400">&mdash;</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-right font-medium {{ $v !== null ? ($v >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                    @if ($v !== null) {{ $v >= 0 ? '' : '-' }}${{ number_format(abs($v), 2) }} @else &mdash; @endif
                                </td>
                            </tr>
                        @endif
                    </tbody>
                    <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 font-semibold dark:border-zinc-600 dark:bg-zinc-800/50">
                        @php $cogsVariance = $hasActuals ? round($projectedCogsTotal - $actualCogsTotal, 2) : null; @endphp
                        <tr>
                            <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">Total COGS</td>
                            <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-zinc-100">${{ number_format($projectedCogsTotal, 2) }}</td>
                            <td class="px-4 py-2 text-right text-zinc-900 dark:text-zinc-100">
                                @if ($hasActuals) ${{ number_format($actualCogsTotal, 2) }} @else &mdash; @endif
                            </td>
                            <td class="px-4 py-2 text-right {{ $cogsVariance !== null ? ($cogsVariance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                @if ($cogsVariance !== null) {{ $cogsVariance >= 0 ? '' : '-' }}${{ number_format(abs($cogsVariance), 2) }} @else &mdash; @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Gross Profit (always visible between dropdowns) --}}
        @php
            $actualGP = $hasActuals ? $customerPrice - $actualCogsTotal : null;
            $gpPercent = $customerPrice > 0 ? round($projectedGP / $customerPrice * 100, 1) : 0;
        @endphp
        <div class="mb-3 flex items-center justify-between rounded-lg px-4 py-2.5 {{ $projectedGP >= 0 ? 'bg-zinc-100 dark:bg-zinc-800' : 'bg-red-50 dark:bg-red-950/20' }}">
            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Gross Profit</span>
            <div class="flex items-center gap-4 text-sm">
                <span class="font-mono font-bold {{ $projectedGP >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    ${{ number_format($projectedGP, 2) }}
                </span>
                <span class="text-xs text-zinc-500">{{ $gpPercent }}% margin</span>
                @if ($actualGP !== null)
                    <span class="font-mono font-medium {{ $actualGP >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                        (actual: ${{ number_format($actualGP, 2) }})
                    </span>
                @endif
            </div>
        </div>

        {{-- DROPDOWN 2: Business Allocations --}}
        @php
            $allocItems = $bd['allocations']['items'] ?? [];
            $hasAllocItems = count($allocItems) > 0;
        @endphp
        @if ($hasAllocItems)
            <div x-data="{ open: false }" class="mb-3">
                <button
                    @click="open = !open"
                    class="flex w-full items-center justify-between rounded-lg border border-zinc-200 bg-white px-4 py-3 text-left transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:bg-zinc-800"
                >
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-medium uppercase tracking-wider text-red-600 dark:text-red-400">Debit</span>
                        <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Business Allocations</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-bold text-red-600 dark:text-red-400">(${{ number_format($projectedAllocTotal, 2) }})</span>
                        <svg x-show="!open" class="h-4 w-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        <svg x-show="open" class="h-4 w-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                    </div>
                </button>

                <div x-show="open" x-collapse class="mt-1 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                        <thead class="bg-zinc-100 dark:bg-zinc-800">
                            <tr>
                                <th class="px-4 py-2 text-left font-medium text-zinc-600 dark:text-zinc-400">Allocation</th>
                                <th class="px-4 py-2 text-right font-medium text-zinc-600 dark:text-zinc-400">Projected</th>
                                <th class="px-4 py-2 text-right font-medium text-zinc-600 dark:text-zinc-400">Actual</th>
                                <th class="px-4 py-2 text-right font-medium text-zinc-600 dark:text-zinc-400">Variance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                            @foreach ($allocItems as $idx => $alloc)
                                @php
                                    $allocActual = $allocationActuals[$idx] ?? null;
                                    $allocVariance = ($allocActual !== null && $allocActual !== '') ? round((float) $alloc['amount'] - (float) $allocActual, 2) : null;
                                @endphp
                                <tr>
                                    <td class="px-4 py-2 text-zinc-700 dark:text-zinc-300">
                                        {{ $alloc['name'] }}
                                        @if (($alloc['type'] ?? '') === 'percent_of_revenue')
                                            <span class="text-xs text-zinc-400">({{ number_format((float) $alloc['value'], 1) }}%)</span>
                                        @elseif (($alloc['type'] ?? '') === 'fixed_per_job')
                                            <span class="text-xs text-zinc-400">(fixed/job)</span>
                                        @elseif (($alloc['type'] ?? '') === 'per_labor_hour')
                                            <span class="text-xs text-zinc-400">(${{ number_format((float) $alloc['value'], 2) }}/hr)</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right font-mono text-zinc-600 dark:text-zinc-400">
                                        ${{ number_format((float) $alloc['amount'], 2) }}
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        @if ($this->canManage)
                                            <input type="number" step="0.01" wire:model.blur="allocationActuals.{{ $idx }}" class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="0.00" />
                                        @elseif ($allocActual !== null && $allocActual !== '')
                                            <span class="font-mono">${{ number_format((float) $allocActual, 2) }}</span>
                                        @else
                                            <span class="text-zinc-400">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-right font-medium {{ $allocVariance !== null ? ($allocVariance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                        @if ($allocVariance !== null) {{ $allocVariance >= 0 ? '' : '-' }}${{ number_format(abs($allocVariance), 2) }} @else &mdash; @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t-2 border-zinc-300 bg-zinc-50 font-semibold dark:border-zinc-600 dark:bg-zinc-800/50">
                            @php
                                $actualAllocTotal = 0;
                                $hasAllocActuals = false;
                                foreach ($allocItems as $idx => $alloc) {
                                    $val = $allocationActuals[$idx] ?? null;
                                    if ($val !== null && $val !== '') {
                                        $actualAllocTotal += (float) $val;
                                        $hasAllocActuals = true;
                                    }
                                }
                                $allocTotalVariance = $hasAllocActuals ? round($projectedAllocTotal - $actualAllocTotal, 2) : null;
                            @endphp
                            <tr>
                                <td class="px-4 py-2 text-zinc-900 dark:text-zinc-100">Total Allocations</td>
                                <td class="px-4 py-2 text-right font-mono text-zinc-900 dark:text-zinc-100">${{ number_format($projectedAllocTotal, 2) }}</td>
                                <td class="px-4 py-2 text-right text-zinc-900 dark:text-zinc-100">
                                    @if ($hasAllocActuals) ${{ number_format($actualAllocTotal, 2) }} @else &mdash; @endif
                                </td>
                                <td class="px-4 py-2 text-right {{ $allocTotalVariance !== null ? ($allocTotalVariance >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                    @if ($allocTotalVariance !== null) {{ $allocTotalVariance >= 0 ? '' : '-' }}${{ number_format(abs($allocTotalVariance), 2) }} @else &mdash; @endif
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        @endif

        {{-- Crew Bonus --}}
        @if (isset($bd['crew_bonus']) && $bd['crew_bonus'])
            @php $crewBonus = $bd['crew_bonus']; @endphp
            <div class="mb-3 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 dark:border-amber-800 dark:bg-amber-950/20">
                <div>
                    <span class="text-sm font-semibold text-amber-700 dark:text-amber-300">Crew Lead Bonus</span>
                    <span class="text-xs text-amber-600 dark:text-amber-400">({{ $crewBonus['crew_name'] }} &mdash; {{ number_format($crewBonus['bonus_percent'], 1) }}%)</span>
                </div>
                <div class="flex items-center gap-3 text-sm">
                    <span class="font-mono font-bold text-amber-700 dark:text-amber-300">${{ number_format($crewBonus['bonus_amount'], 2) }}</span>
                    @if ($this->canManage)
                        <input type="number" step="0.01" wire:model.blur="actualCrewBonus" class="w-24 rounded border border-amber-300 px-2 py-1 text-right text-sm dark:border-amber-600 dark:bg-zinc-800 dark:text-zinc-200" placeholder="Actual" />
                    @elseif ($actualCrewBonus !== null && $actualCrewBonus !== '')
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">${{ number_format((float) $actualCrewBonus, 2) }}</span>
                    @endif
                </div>
            </div>
        @endif

        {{-- Net Profit --}}
        @php
            $netPercent = $customerPrice > 0 ? round($projectedNet / $customerPrice * 100, 1) : 0;
            $bonusAmount = $bd['crew_bonus']['bonus_amount'] ?? 0;
            $projectedNetAfterBonus = $projectedNet - $bonusAmount;
            $netAfterBonusPercent = $customerPrice > 0 ? round($projectedNetAfterBonus / $customerPrice * 100, 1) : 0;
        @endphp
        <div class="mb-2 flex items-center justify-between rounded-lg px-4 py-2.5 {{ $projectedNet >= 0 ? 'bg-emerald-100 dark:bg-emerald-900/30' : 'bg-red-100 dark:bg-red-900/30' }}">
            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Net Profit</span>
            <div class="flex items-center gap-4 text-sm">
                <span class="font-mono text-lg font-bold {{ $projectedNet >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">
                    ${{ number_format($projectedNet, 2) }}
                </span>
                <span class="text-xs text-zinc-500">{{ $netPercent }}%</span>
            </div>
        </div>

        @if ($bonusAmount > 0)
            <div class="mb-3 flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-2 dark:border-zinc-700">
                <span class="text-sm text-zinc-600 dark:text-zinc-400">Net Profit After Bonus</span>
                <div class="flex items-center gap-4 text-sm">
                    <span class="font-mono font-bold {{ $projectedNetAfterBonus >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                        ${{ number_format($projectedNetAfterBonus, 2) }}
                    </span>
                    <span class="text-xs text-zinc-500">{{ $netAfterBonusPercent }}% margin</span>
                </div>
            </div>
        @endif

        {{-- Notes --}}
        @if ($this->canManage)
            <div class="mt-4">
                <flux:field>
                    <flux:label>Actual Notes</flux:label>
                    <flux:textarea wire:model.blur="actualNotes" rows="2" placeholder="Notes about actual costs..." />
                </flux:field>
            </div>
        @elseif ($actualNotes)
            <div class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                <strong>Notes:</strong> {{ $actualNotes }}
            </div>
        @endif

        {{-- Action Buttons --}}
        @if ($this->canManage)
            <div class="mt-4 flex items-center gap-3">
                <flux:button variant="primary" size="sm" wire:click="saveActuals" icon="check">
                    Save Actuals
                </flux:button>

                @if (! $card->journal_entry_id)
                    <flux:button variant="ghost" size="sm" wire:click="postToJournal" icon="document-text">
                        Post to Journal
                    </flux:button>
                @else
                    <flux:badge color="green" size="sm">
                        Journal Entry: {{ $card->journalEntry?->entry_number ?? 'Linked' }}
                    </flux:badge>
                    <flux:button variant="ghost" size="sm" wire:click="recreateJournalEntry" icon="arrow-path"
                        wire:confirm="This will void the current journal entry and create a new one. Continue?">
                        Recreate
                    </flux:button>
                @endif
            </div>
        @endif
    @endif
  </div>
</div>
