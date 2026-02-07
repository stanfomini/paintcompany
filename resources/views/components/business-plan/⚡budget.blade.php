<?php

use App\Models\BudgetCategory;
use App\Models\BudgetLineItem;
use App\Models\CompanySetting;
use App\Services\BudgetSyncService;
use App\Services\BusinessPlanService;
use Database\Seeders\BudgetCategorySeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $year;

    public bool $showEditModal = false;

    public ?int $editingCategoryId = null;

    public string $editCategoryName = '';

    public string $editPriorYearActual = '0';

    public string $editCurrentYearPlan = '0';

    public string $editCurrentYearActual = '0';

    public string $editNotes = '';

    public bool $syncing = false;

    public ?string $lastSyncMessage = null;

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
    public function budget(): array
    {
        return app(BusinessPlanService::class)->getBudgetForYear($this->year);
    }

    #[Computed]
    public function accountingMode(): string
    {
        return CompanySetting::getInstance()->accounting_mode ?? 'cash';
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
        unset($this->budget);
        $this->lastSyncMessage = null;
    }

    public function syncActuals(): void
    {
        $this->authorize('business_plan.manage');

        $this->syncing = true;

        $result = app(BudgetSyncService::class)->syncActualsForYear($this->year);

        $this->syncing = false;
        $this->lastSyncMessage = "Synced {$result['synced']} categories from live data.";
        unset($this->budget);
    }

    public function openEdit(int $categoryId): void
    {
        $this->authorize('business_plan.manage');

        $settings = CompanySetting::getInstance();
        $category = BudgetCategory::findOrFail($categoryId);

        $item = BudgetLineItem::where('company_setting_id', $settings->id)
            ->where('budget_category_id', $categoryId)
            ->where('year', $this->year)
            ->first();

        $this->editingCategoryId = $categoryId;
        $this->editCategoryName = $category->name;
        $this->editPriorYearActual = (string) ($item?->prior_year_actual ?? 0);
        $this->editCurrentYearPlan = (string) ($item?->current_year_plan ?? 0);
        $this->editCurrentYearActual = (string) ($item?->current_year_actual ?? 0);
        $this->editNotes = $item?->notes ?? '';
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'editPriorYearActual' => 'required|numeric|min:0',
            'editCurrentYearPlan' => 'required|numeric|min:0',
            'editCurrentYearActual' => 'required|numeric|min:0',
        ]);

        $settings = CompanySetting::getInstance();

        BudgetLineItem::updateOrCreate(
            [
                'company_setting_id' => $settings->id,
                'budget_category_id' => $this->editingCategoryId,
                'year' => $this->year,
            ],
            [
                'prior_year_actual' => $this->editPriorYearActual,
                'current_year_plan' => $this->editCurrentYearPlan,
                'current_year_actual' => $this->editCurrentYearActual,
                'notes' => $this->editNotes ?: null,
                'sync_source' => 'manual',
                'synced_at' => null,
            ]
        );

        $this->showEditModal = false;
        $this->editingCategoryId = null;
        unset($this->budget);
    }

    public function seedDefaults(): void
    {
        $this->authorize('business_plan.manage');

        (new BudgetCategorySeeder)->run();

        unset($this->budget);
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('business_plan.manage');

        return app(BusinessPlanService::class)->exportToCsv($this->year);
    }

    private function percentOfRevenue(float $amount, float $revenue): float
    {
        if ($revenue <= 0) {
            return 0;
        }

        return round(($amount / $revenue) * 100, 1);
    }

    private function accountingModeLabel(): string
    {
        return match ($this->accountingMode) {
            'accrual' => 'Accrual',
            'modified_cash' => 'Modified Cash',
            default => 'Cash',
        };
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">Budget (P&L)</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Annual profit & loss with plan vs actual tracking.
            </flux:text>
        </div>

        <div class="flex items-center gap-3">
            <flux:badge size="sm" variant="outline" icon="calculator">
                {{ $this->accountingModeLabel() }} Basis
            </flux:badge>

            <flux:select wire:model.live="year" class="w-28">
                @foreach ($this->yearOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            @if ($this->canManage)
                <flux:button
                    wire:click="syncActuals"
                    wire:loading.attr="disabled"
                    wire:target="syncActuals"
                    variant="ghost"
                    size="sm"
                    icon="arrow-path"
                >
                    <span wire:loading.remove wire:target="syncActuals">Sync Actuals</span>
                    <span wire:loading wire:target="syncActuals">Syncing...</span>
                </flux:button>
                <flux:button wire:click="exportCsv" variant="ghost" size="sm" icon="arrow-down-tray">
                    Export CSV
                </flux:button>
            @endif
        </div>
    </div>

    @if ($lastSyncMessage)
        <div class="mb-4">
            <flux:callout variant="outline" icon="check-circle" class="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20">
                <flux:callout.text class="text-green-700 dark:text-green-300">{{ $lastSyncMessage }}</flux:callout.text>
            </flux:callout>
        </div>
    @endif

    @php
        $budget = $this->budget;
        $hasCategories = collect($budget['sections'])->sum(fn ($s) => count($s['rows'])) > 0;
        $revPrior = $budget['revenue']['prior_year_actual'];
        $revPlan = $budget['revenue']['current_year_plan'];
        $revActual = $budget['revenue']['current_year_actual'];
    @endphp

    @if (! $hasCategories)
        <flux:callout variant="outline" icon="banknotes">
            <flux:callout.heading>No budget categories configured</flux:callout.heading>
            <flux:callout.text>
                @if ($this->canManage)
                    Load default budget categories to get started with P&L tracking.
                @else
                    Budget categories have not been configured yet.
                @endif
            </flux:callout.text>
        </flux:callout>

        @if ($this->canManage)
            <div class="mt-4">
                <flux:button wire:click="seedDefaults" variant="primary" size="sm" icon="sparkles">
                    Load Default Categories
                </flux:button>
            </div>
        @endif
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Category
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Prior Year Actual
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            %
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ $this->year }} Plan
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            %
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            {{ $this->year }} Actual
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            %
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @foreach (\App\Models\BudgetCategory::SECTIONS as $sectionKey)
                        @php $section = $budget['sections'][$sectionKey]; @endphp

                        {{-- Section Header --}}
                        <tr class="bg-zinc-100 dark:bg-zinc-800">
                            <td colspan="7" class="px-4 py-2 text-sm font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">
                                {{ $section['label'] }}
                            </td>
                        </tr>

                        {{-- Category Rows --}}
                        @foreach ($section['rows'] as $row)
                            <tr
                                wire:key="row-{{ $row['category_id'] }}"
                                @if ($this->canManage)
                                    wire:click="openEdit({{ $row['category_id'] }})"
                                    class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                @else
                                    class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                @endif
                            >
                                <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                    <div class="flex items-center gap-2">
                                        {{ $row['name'] }}
                                        @if ($row['sync_source'] && $row['sync_source'] !== 'manual')
                                            <flux:badge size="sm" variant="pill" color="sky" class="text-[10px]">
                                                synced
                                            </flux:badge>
                                        @endif
                                    </div>
                                    @if ($row['synced_at'])
                                        <div class="text-[10px] text-zinc-400 dark:text-zinc-500">
                                            {{ $row['synced_at']->diffForHumans() }}
                                        </div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                    ${{ number_format($row['prior_year_actual'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ $this->percentOfRevenue($row['prior_year_actual'], $revPrior) }}%
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                    ${{ number_format($row['current_year_plan'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ $this->percentOfRevenue($row['current_year_plan'], $revPlan) }}%
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-300">
                                    ${{ number_format($row['current_year_actual'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2 text-right text-xs text-zinc-400 dark:text-zinc-500">
                                    {{ $this->percentOfRevenue($row['current_year_actual'], $revActual) }}%
                                </td>
                            </tr>
                        @endforeach

                        {{-- Section Total --}}
                        <tr class="border-t-2 border-zinc-300 bg-zinc-50 font-semibold dark:border-zinc-600 dark:bg-zinc-800/50">
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                Total {{ $section['label'] }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($section['totals']['prior_year_actual'], 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $this->percentOfRevenue($section['totals']['prior_year_actual'], $revPrior) }}%
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($section['totals']['current_year_plan'], 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $this->percentOfRevenue($section['totals']['current_year_plan'], $revPlan) }}%
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($section['totals']['current_year_actual'], 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $this->percentOfRevenue($section['totals']['current_year_actual'], $revActual) }}%
                            </td>
                        </tr>

                        {{-- Gross Profit row after Variable Expenses --}}
                        @if ($sectionKey === \App\Models\BudgetCategory::SECTION_VARIABLE_EXPENSE)
                            @php $gp = $budget['computed']['gross_profit']; @endphp
                            <tr class="border-t-2 border-green-300 bg-green-50 font-bold dark:border-green-800 dark:bg-green-900/20">
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-green-800 dark:text-green-300">
                                    GROSS PROFIT
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $gp['prior_year_actual'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    ${{ number_format($gp['prior_year_actual'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-green-600 dark:text-green-500">
                                    {{ $this->percentOfRevenue($gp['prior_year_actual'], $revPrior) }}%
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $gp['current_year_plan'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    ${{ number_format($gp['current_year_plan'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-green-600 dark:text-green-500">
                                    {{ $this->percentOfRevenue($gp['current_year_plan'], $revPlan) }}%
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $gp['current_year_actual'] >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    ${{ number_format($gp['current_year_actual'], 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-green-600 dark:text-green-500">
                                    {{ $this->percentOfRevenue($gp['current_year_actual'], $revActual) }}%
                                </td>
                            </tr>
                        @endif
                    @endforeach

                    {{-- Total Fixed Expenses --}}
                    @php $tf = $budget['computed']['total_fixed']; @endphp
                    <tr class="border-t-2 border-zinc-400 bg-zinc-100 font-bold dark:border-zinc-500 dark:bg-zinc-800">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                            TOTAL FIXED EXPENSES
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($tf['prior_year_actual'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $this->percentOfRevenue($tf['prior_year_actual'], $revPrior) }}%
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($tf['current_year_plan'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $this->percentOfRevenue($tf['current_year_plan'], $revPlan) }}%
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($tf['current_year_actual'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $this->percentOfRevenue($tf['current_year_actual'], $revActual) }}%
                        </td>
                    </tr>

                    {{-- Net Profit --}}
                    @php $np = $budget['computed']['net_profit']; @endphp
                    <tr class="border-t-2 border-blue-300 bg-blue-50 font-bold dark:border-blue-800 dark:bg-blue-900/20">
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-blue-800 dark:text-blue-300">
                            NET PROFIT
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $np['prior_year_actual'] >= 0 ? 'text-blue-700 dark:text-blue-400' : 'text-red-600 dark:text-red-400' }}">
                            ${{ number_format($np['prior_year_actual'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-blue-600 dark:text-blue-500">
                            {{ $this->percentOfRevenue($np['prior_year_actual'], $revPrior) }}%
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $np['current_year_plan'] >= 0 ? 'text-blue-700 dark:text-blue-400' : 'text-red-600 dark:text-red-400' }}">
                            ${{ number_format($np['current_year_plan'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-blue-600 dark:text-blue-500">
                            {{ $this->percentOfRevenue($np['current_year_plan'], $revPlan) }}%
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm {{ $np['current_year_actual'] >= 0 ? 'text-blue-700 dark:text-blue-400' : 'text-red-600 dark:text-red-400' }}">
                            ${{ number_format($np['current_year_actual'], 2) }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right text-xs text-blue-600 dark:text-blue-500">
                            {{ $this->percentOfRevenue($np['current_year_actual'], $revActual) }}%
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    @if ($this->canManage)
        {{-- Edit Line Item Modal --}}
        <flux:modal wire:model="showEditModal" name="edit-budget-item" class="md:w-[32rem]">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Edit Budget Item</flux:heading>
                    <flux:text class="mt-2">{{ $editCategoryName }} - {{ $this->year }}</flux:text>
                </div>

                <div class="grid grid-cols-1 gap-4">
                    <flux:input
                        wire:model="editPriorYearActual"
                        label="Prior Year Actual ($)"
                        type="number"
                        step="0.01"
                        min="0"
                    />

                    <flux:input
                        wire:model="editCurrentYearPlan"
                        label="{{ $this->year }} Plan ($)"
                        type="number"
                        step="0.01"
                        min="0"
                    />

                    <flux:input
                        wire:model="editCurrentYearActual"
                        label="{{ $this->year }} Actual ($)"
                        type="number"
                        step="0.01"
                        min="0"
                    />

                    <flux:textarea
                        wire:model="editNotes"
                        label="Notes"
                        rows="2"
                        placeholder="Optional notes..."
                    />
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveEdit" variant="primary">Save Changes</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
