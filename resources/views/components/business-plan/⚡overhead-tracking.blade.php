<?php

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use App\Models\OverheadEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $year;

    public bool $showEditModal = false;

    public ?int $editingCategoryId = null;

    public ?int $editingMonth = null;

    public string $editAmount = '0';

    public string $editNotes = '';

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
    public function categories(): \Illuminate\Database\Eloquent\Collection
    {
        $settings = CompanySetting::getInstance();

        return BudgetCategory::where('company_setting_id', $settings->id)
            ->whereIn('section', [
                BudgetCategory::SECTION_VEHICLE_EXPENSE,
                BudgetCategory::SECTION_BUSINESS_EXPENSE,
            ])
            ->orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<string, array<int, float>>
     */
    #[Computed]
    public function entries(): array
    {
        $settings = CompanySetting::getInstance();

        $raw = OverheadEntry::where('company_setting_id', $settings->id)
            ->where('year', $this->year)
            ->get();

        $map = [];
        foreach ($raw as $entry) {
            $key = $entry->budget_category_id . '-' . $entry->month;
            $map[$key] = [
                'amount' => (float) $entry->amount,
                'notes' => $entry->notes,
            ];
        }

        return $map;
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
        unset($this->entries, $this->categories);
    }

    public function getAmount(int $categoryId, int $month): float
    {
        $key = $categoryId . '-' . $month;

        return $this->entries[$key]['amount'] ?? 0.00;
    }

    public function getMonthTotal(int $month): float
    {
        $total = 0;
        foreach ($this->categories as $category) {
            $total += $this->getAmount($category->id, $month);
        }

        return $total;
    }

    public function getCategoryAnnualTotal(int $categoryId): float
    {
        $total = 0;
        for ($m = 1; $m <= 12; $m++) {
            $total += $this->getAmount($categoryId, $m);
        }

        return $total;
    }

    public function getGrandTotal(): float
    {
        $total = 0;
        foreach ($this->categories as $category) {
            $total += $this->getCategoryAnnualTotal($category->id);
        }

        return $total;
    }

    public function openEdit(int $categoryId, int $month): void
    {
        $this->authorize('business_plan.manage');

        $this->editingCategoryId = $categoryId;
        $this->editingMonth = $month;

        $key = $categoryId . '-' . $month;
        $this->editAmount = (string) ($this->entries[$key]['amount'] ?? '0');
        $this->editNotes = $this->entries[$key]['notes'] ?? '';
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'editAmount' => 'required|numeric|min:0',
            'editingCategoryId' => 'required|integer|exists:budget_categories,id',
            'editingMonth' => 'required|integer|min:1|max:12',
        ]);

        $settings = CompanySetting::getInstance();

        OverheadEntry::updateOrCreate(
            [
                'company_setting_id' => $settings->id,
                'budget_category_id' => $this->editingCategoryId,
                'year' => $this->year,
                'month' => $this->editingMonth,
            ],
            [
                'amount' => $this->editAmount,
                'notes' => $this->editNotes ?: null,
            ]
        );

        $this->showEditModal = false;
        $this->editingCategoryId = null;
        $this->editingMonth = null;
        unset($this->entries);
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('business_plan.manage');

        $categories = $this->categories;
        $filename = "overhead-tracking-{$this->year}.csv";

        return response()->streamDownload(function () use ($categories) {
            $handle = fopen('php://output', 'w');

            $header = ['Month'];
            foreach ($categories as $cat) {
                $header[] = $cat->name;
            }
            $header[] = 'Monthly Total';
            fputcsv($handle, $header);

            $months = [
                1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
                5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
                9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
            ];

            foreach ($months as $m => $name) {
                $row = [$name];
                foreach ($categories as $cat) {
                    $row[] = $this->getAmount($cat->id, $m);
                }
                $row[] = $this->getMonthTotal($m);
                fputcsv($handle, $row);
            }

            $totalsRow = ['Annual Total'];
            foreach ($categories as $cat) {
                $totalsRow[] = $this->getCategoryAnnualTotal($cat->id);
            }
            $totalsRow[] = $this->getGrandTotal();
            fputcsv($handle, $totalsRow);

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    protected function monthName(int $month): string
    {
        return \DateTime::createFromFormat('!m', (string) $month)->format('F');
    }

    protected function monthShort(int $month): string
    {
        return \DateTime::createFromFormat('!m', (string) $month)->format('M');
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">Overhead Tracking</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Track monthly overhead expenses by category.
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
                <flux:button wire:click="exportCsv" variant="ghost" size="sm" icon="arrow-down-tray">
                    Export CSV
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Spreadsheet Grid --}}
    @if ($this->categories->isEmpty())
        <flux:callout variant="outline" icon="building-office-2">
            <flux:callout.heading>No overhead categories</flux:callout.heading>
            <flux:callout.text>Overhead categories come from your budget setup (Vehicle Expenses and Business Expenses). Set up your budget categories first.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="sticky left-0 z-10 bg-zinc-50 px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            Month
                        </th>
                        @foreach ($this->categories as $category)
                            <th wire:key="header-{{ $category->id }}" class="px-3 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400" title="{{ $category->section_label }}">
                                {{ $category->name }}
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            Monthly Total
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @for ($month = 1; $month <= 12; $month++)
                        <tr wire:key="row-{{ $month }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="sticky left-0 z-10 whitespace-nowrap bg-white px-4 py-2 text-sm font-medium text-zinc-900 dark:bg-zinc-900 dark:text-zinc-100">
                                {{ $this->monthName($month) }}
                            </td>
                            @foreach ($this->categories as $category)
                                @php $amount = $this->getAmount($category->id, $month); @endphp
                                <td wire:key="cell-{{ $category->id }}-{{ $month }}"
                                    class="whitespace-nowrap px-3 py-2 text-right text-sm text-zinc-600 dark:text-zinc-300 {{ $this->canManage ? 'cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/20' : '' }}"
                                    @if ($this->canManage) wire:click="openEdit({{ $category->id }}, {{ $month }})" @endif
                                >
                                    @if ($amount > 0)
                                        ${{ number_format($amount, 2) }}
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600">-</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-4 py-2 text-right text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                @php $monthTotal = $this->getMonthTotal($month); @endphp
                                @if ($monthTotal > 0)
                                    ${{ number_format($monthTotal, 2) }}
                                @else
                                    <span class="text-zinc-300 dark:text-zinc-600">-</span>
                                @endif
                            </td>
                        </tr>
                    @endfor
                </tbody>
                <tfoot class="bg-zinc-100 font-semibold dark:bg-zinc-800">
                    <tr>
                        <td class="sticky left-0 z-10 bg-zinc-100 px-4 py-3 text-sm text-zinc-900 dark:bg-zinc-800 dark:text-zinc-100">
                            Annual Total
                        </td>
                        @foreach ($this->categories as $category)
                            @php $catTotal = $this->getCategoryAnnualTotal($category->id); @endphp
                            <td wire:key="total-{{ $category->id }}" class="whitespace-nowrap px-3 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                @if ($catTotal > 0)
                                    ${{ number_format($catTotal, 2) }}
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-500">-</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                            ${{ number_format($this->getGrandTotal(), 2) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    @if ($this->canManage)
        {{-- Edit Cell Modal --}}
        <flux:modal wire:model="showEditModal" class="md:w-96">
            <form wire:submit="saveEdit">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">Edit Overhead Amount</flux:heading>
                        @if ($editingMonth)
                            <flux:text class="mt-2">
                                {{ $this->monthName($editingMonth) }} {{ $year }}
                                @if ($editingCategoryId)
                                    @php $editCat = $this->categories->firstWhere('id', $editingCategoryId); @endphp
                                    @if ($editCat)
                                        &mdash; {{ $editCat->name }}
                                    @endif
                                @endif
                            </flux:text>
                        @endif
                    </div>

                    <flux:field>
                        <flux:label>Amount ($)</flux:label>
                        <flux:input wire:model="editAmount" type="number" step="0.01" min="0" />
                        <flux:error name="editAmount" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model="editNotes" rows="2" placeholder="Optional notes..." />
                    </flux:field>

                    <div class="flex justify-end gap-3">
                        <flux:button variant="ghost" wire:click="$set('showEditModal', false)" type="button">
                            Cancel
                        </flux:button>
                        <flux:button type="submit" variant="primary">
                            Save
                        </flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
