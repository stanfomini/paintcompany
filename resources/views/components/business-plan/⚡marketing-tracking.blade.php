<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\MarketingTrackingEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $year;

    public bool $showEditModal = false;

    public bool $showAddModal = false;

    public bool $showDeleteConfirm = false;

    public ?int $editingEntryId = null;

    public ?int $deletingEntryId = null;

    public string $editSourceName = '';

    public string $editMarketingExpense = '0';

    public int $editLeads = 0;

    public int $editEstimates = 0;

    public int $editJobsBooked = 0;

    public string $editTotalSales = '0';

    public string $editNotes = '';

    public string $addSourceName = '';

    public function mount(): void
    {
        $this->year = now()->year;
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

    #[Computed]
    public function entries(): \Illuminate\Database\Eloquent\Collection
    {
        $settings = CompanySetting::getInstance();

        return MarketingTrackingEntry::where('company_setting_id', $settings->id)
            ->where('year', $this->year)
            ->orderBy('sort_order')
            ->orderBy('source_name')
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $entries = $this->entries;

        return [
            'marketing_expense' => $entries->sum(fn ($e) => (float) $e->marketing_expense),
            'leads' => $entries->sum('leads'),
            'estimates' => $entries->sum('estimates'),
            'jobs_booked' => $entries->sum('jobs_booked'),
            'total_sales' => $entries->sum(fn ($e) => (float) $e->total_sales),
        ];
    }

    #[Computed]
    public function totalCostPerLead(): float
    {
        $totals = $this->totals;

        if ($totals['leads'] <= 0) {
            return 0;
        }

        return round($totals['marketing_expense'] / $totals['leads'], 2);
    }

    #[Computed]
    public function totalRoiPercent(): float
    {
        $totals = $this->totals;

        if ($totals['marketing_expense'] <= 0) {
            return 0;
        }

        return round(($totals['total_sales'] - $totals['marketing_expense']) / $totals['marketing_expense'] * 100, 1);
    }

    #[Computed]
    public function yearOptions(): array
    {
        $current = now()->year;

        $years = [];
        for ($y = $current - 2; $y <= $current + 1; $y++) {
            $years[$y] = (string) $y;
        }

        return $years;
    }

    public function updatedYear(): void
    {
        unset($this->entries, $this->totals, $this->totalCostPerLead, $this->totalRoiPercent);
    }

    public function openAdd(): void
    {
        $this->authorize('business_plan.manage');
        $this->addSourceName = '';
        $this->showAddModal = true;
    }

    public function addSource(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'addSourceName' => 'required|string|max:255',
        ], [
            'addSourceName.required' => 'Source name is required.',
        ]);

        $settings = CompanySetting::getInstance();

        $exists = MarketingTrackingEntry::where('company_setting_id', $settings->id)
            ->where('year', $this->year)
            ->where('source_name', $this->addSourceName)
            ->exists();

        if ($exists) {
            $this->addError('addSourceName', 'This source already exists for the selected year.');

            return;
        }

        $maxSort = MarketingTrackingEntry::where('company_setting_id', $settings->id)
            ->where('year', $this->year)
            ->max('sort_order') ?? -1;

        MarketingTrackingEntry::create([
            'company_setting_id' => $settings->id,
            'year' => $this->year,
            'source_name' => $this->addSourceName,
            'marketing_expense' => 0,
            'leads' => 0,
            'estimates' => 0,
            'jobs_booked' => 0,
            'total_sales' => 0,
            'is_manual' => true,
            'sort_order' => $maxSort + 1,
        ]);

        $this->showAddModal = false;
        $this->addSourceName = '';
        unset($this->entries, $this->totals, $this->totalCostPerLead, $this->totalRoiPercent);
    }

    public function openEdit(int $entryId): void
    {
        $this->authorize('business_plan.manage');

        $entry = MarketingTrackingEntry::findOrFail($entryId);
        $this->editingEntryId = $entry->id;
        $this->editSourceName = $entry->source_name;
        $this->editMarketingExpense = (string) $entry->marketing_expense;
        $this->editLeads = $entry->leads;
        $this->editEstimates = $entry->estimates;
        $this->editJobsBooked = $entry->jobs_booked;
        $this->editTotalSales = (string) $entry->total_sales;
        $this->editNotes = $entry->notes ?? '';
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'editSourceName' => 'required|string|max:255',
            'editMarketingExpense' => 'required|numeric|min:0',
            'editLeads' => 'required|integer|min:0',
            'editEstimates' => 'required|integer|min:0',
            'editJobsBooked' => 'required|integer|min:0',
            'editTotalSales' => 'required|numeric|min:0',
        ]);

        $entry = MarketingTrackingEntry::findOrFail($this->editingEntryId);

        $entry->update([
            'source_name' => $this->editSourceName,
            'marketing_expense' => $this->editMarketingExpense,
            'leads' => $this->editLeads,
            'estimates' => $this->editEstimates,
            'jobs_booked' => $this->editJobsBooked,
            'total_sales' => $this->editTotalSales,
            'notes' => $this->editNotes ?: null,
        ]);

        $this->showEditModal = false;
        $this->editingEntryId = null;
        unset($this->entries, $this->totals, $this->totalCostPerLead, $this->totalRoiPercent);
    }

    public function confirmDelete(int $entryId): void
    {
        $this->authorize('business_plan.manage');
        $this->deletingEntryId = $entryId;
        $this->showDeleteConfirm = true;
    }

    public function deleteEntry(): void
    {
        $this->authorize('business_plan.manage');

        if ($this->deletingEntryId) {
            MarketingTrackingEntry::where('id', $this->deletingEntryId)->delete();
        }

        $this->showDeleteConfirm = false;
        $this->deletingEntryId = null;
        unset($this->entries, $this->totals, $this->totalCostPerLead, $this->totalRoiPercent);
    }

    public function syncFromDeals(): void
    {
        $this->authorize('business_plan.manage');

        $settings = CompanySetting::getInstance();

        $dealStats = Deal::query()
            ->whereYear('created_at', $this->year)
            ->whereNotNull('lead_source')
            ->where('lead_source', '!=', '')
            ->selectRaw('lead_source, COUNT(*) as lead_count')
            ->selectRaw('SUM(CASE WHEN stage NOT IN (\'lead\', \'cancelled\') THEN 1 ELSE 0 END) as estimate_count')
            ->selectRaw('SUM(CASE WHEN stage IN (\'completed\', \'paid\', \'signed_accepted\') THEN 1 ELSE 0 END) as booked_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN stage IN (\'completed\', \'paid\', \'signed_accepted\') THEN actual_value ELSE 0 END), 0) as sales_total')
            ->groupBy('lead_source')
            ->get();

        foreach ($dealStats as $stat) {
            MarketingTrackingEntry::updateOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $this->year,
                    'source_name' => $stat->lead_source,
                ],
                [
                    'leads' => $stat->lead_count,
                    'estimates' => $stat->estimate_count,
                    'jobs_booked' => $stat->booked_count,
                    'total_sales' => $stat->sales_total,
                    'is_manual' => false,
                ]
            );
        }

        unset($this->entries, $this->totals, $this->totalCostPerLead, $this->totalRoiPercent);
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('business_plan.manage');

        $entries = $this->entries;
        $filename = "marketing-tracking-{$this->year}.csv";

        return response()->streamDownload(function () use ($entries) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Source Name',
                'Marketing Expense',
                'Leads',
                'Estimates',
                'Jobs Booked',
                'Total Sales',
                'Cost Per Lead',
                'ROI %',
                'Notes',
            ]);

            foreach ($entries as $entry) {
                fputcsv($handle, [
                    $entry->source_name,
                    $entry->marketing_expense,
                    $entry->leads,
                    $entry->estimates,
                    $entry->jobs_booked,
                    $entry->total_sales,
                    $entry->cost_per_lead,
                    $entry->roi_percent,
                    $entry->notes ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function seedDefaults(): void
    {
        $this->authorize('business_plan.manage');

        $settings = CompanySetting::getInstance();

        foreach (MarketingTrackingEntry::defaultSources() as $index => $source) {
            MarketingTrackingEntry::firstOrCreate(
                [
                    'company_setting_id' => $settings->id,
                    'year' => $this->year,
                    'source_name' => $source,
                ],
                [
                    'marketing_expense' => 0,
                    'leads' => 0,
                    'estimates' => 0,
                    'jobs_booked' => 0,
                    'total_sales' => 0,
                    'is_manual' => false,
                    'sort_order' => $index,
                ]
            );
        }

        unset($this->entries, $this->totals, $this->totalCostPerLead, $this->totalRoiPercent);
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">Marketing Tracking</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Track marketing spend, leads, and ROI by source.
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            {{-- Year Selector --}}
            <flux:select wire:model.live="year" class="w-28">
                @foreach ($this->yearOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            @if ($this->canManage)
                <flux:button wire:click="syncFromDeals" variant="ghost" size="sm" icon="arrow-path">
                    Sync from Deals
                </flux:button>

                <flux:button wire:click="exportCsv" variant="ghost" size="sm" icon="arrow-down-tray">
                    Export CSV
                </flux:button>

                <flux:button wire:click="openAdd" variant="primary" size="sm" icon="plus">
                    Add Source
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Entries Table --}}
    @if ($this->entries->isEmpty())
        <flux:callout variant="outline" icon="megaphone">
            <flux:callout.heading>No marketing sources for {{ $this->year }}</flux:callout.heading>
            <flux:callout.text>
                @if ($this->canManage)
                    Get started by adding marketing sources or loading defaults.
                @else
                    No marketing tracking data has been entered for this year yet.
                @endif
            </flux:callout.text>
        </flux:callout>

        @if ($this->canManage)
            <div class="mt-4">
                <flux:button wire:click="seedDefaults" variant="primary" size="sm" icon="sparkles">
                    Load Default Sources
                </flux:button>
            </div>
        @endif
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Source
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Expense
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Leads
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Estimates
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Jobs
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Sales
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            CPL
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            ROI %
                        </th>
                        @if ($this->canManage)
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                                Actions
                            </th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @foreach ($this->entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $entry->source_name }}
                                @if ($entry->is_manual)
                                    <flux:badge size="sm" variant="outline" class="ml-1">Manual</flux:badge>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                ${{ number_format((float) $entry->marketing_expense, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                {{ number_format($entry->leads) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                {{ number_format($entry->estimates) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                {{ number_format($entry->jobs_booked) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                ${{ number_format((float) $entry->total_sales, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                ${{ number_format($entry->cost_per_lead, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $entry->roi_percent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ number_format($entry->roi_percent, 1) }}%
                            </td>
                            @if ($this->canManage)
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button wire:click="openEdit({{ $entry->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                        <flux:button wire:click="confirmDelete({{ $entry->id }})" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-700" />
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-zinc-100 dark:bg-zinc-800 font-semibold">
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                            Totals
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($this->totals['marketing_expense'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            {{ number_format($this->totals['leads']) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            {{ number_format($this->totals['estimates']) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            {{ number_format($this->totals['jobs_booked']) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($this->totals['total_sales'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($this->totalCostPerLead, 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $this->totalRoiPercent >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ number_format($this->totalRoiPercent, 1) }}%
                        </td>
                        @if ($this->canManage)
                            <td></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    @if ($this->canManage)
        {{-- Add Source Modal --}}
        <flux:modal wire:model="showAddModal" name="add-source" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add Marketing Source</flux:heading>
                    <flux:text class="mt-2">Add a new marketing source to track for {{ $this->year }}.</flux:text>
                </div>

                <flux:input
                    wire:model="addSourceName"
                    label="Source Name"
                    placeholder="e.g. Google AdWords"
                />

                @error('addSourceName')
                    <flux:text class="text-sm text-red-500">{{ $message }}</flux:text>
                @enderror

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('showAddModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="addSource" variant="primary">Add Source</flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Edit Entry Modal --}}
        <flux:modal wire:model="showEditModal" name="edit-entry" class="md:w-[32rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Edit Marketing Source</flux:heading>
                    <flux:text class="mt-2">Update tracking data for this marketing source.</flux:text>
                </div>

                <flux:input
                    wire:model="editSourceName"
                    label="Source Name"
                />

                <div class="grid grid-cols-2 gap-4">
                    <flux:input
                        wire:model="editMarketingExpense"
                        label="Marketing Expense ($)"
                        type="number"
                        step="0.01"
                        min="0"
                    />

                    <flux:input
                        wire:model="editLeads"
                        label="Leads"
                        type="number"
                        min="0"
                    />

                    <flux:input
                        wire:model="editEstimates"
                        label="Estimates"
                        type="number"
                        min="0"
                    />

                    <flux:input
                        wire:model="editJobsBooked"
                        label="Jobs Booked"
                        type="number"
                        min="0"
                    />

                    <flux:input
                        wire:model="editTotalSales"
                        label="Total Sales ($)"
                        type="number"
                        step="0.01"
                        min="0"
                        class="col-span-2"
                    />
                </div>

                <flux:textarea
                    wire:model="editNotes"
                    label="Notes"
                    rows="2"
                    placeholder="Optional notes..."
                />

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveEdit" variant="primary">Save Changes</flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Delete Confirmation Modal --}}
        <flux:modal wire:model="showDeleteConfirm" name="delete-confirm" class="md:w-96">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete Source</flux:heading>
                    <flux:text class="mt-2">Are you sure you want to delete this marketing source? This action cannot be undone.</flux:text>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('showDeleteConfirm', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="deleteEntry" variant="danger">Delete</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
