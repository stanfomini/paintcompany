<?php

use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = '';

    public string $dateFrom = '';
    public string $dateTo = '';

    // Create entry modal
    public bool $showCreateModal = false;
    public string $entryDescription = '';
    public string $entryTransactionDate = '';
    public string $entryRecognitionDate = '';
    public array $entryLines = [];

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
        return Auth::user()?->can('accounting.manage') ?? false;
    }

    #[Computed]
    public function entries()
    {
        $settings = CompanySetting::getInstance();

        $query = JournalEntry::where('company_setting_id', $settings->id)
            ->with(['lines.account', 'createdBy'])
            ->latest('created_at');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('entry_number', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%");
            });
        }

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->dateFrom) {
            $query->where('transaction_date', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->where('transaction_date', '<=', $this->dateTo);
        }

        return $query->paginate(15);
    }

    #[Computed]
    public function accounts()
    {
        $settings = CompanySetting::getInstance();

        return Account::where('company_setting_id', $settings->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->entries);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        unset($this->entries);
    }

    public function openCreateModal(): void
    {
        $this->authorize('accounting.manage');

        $this->entryDescription = '';
        $this->entryTransactionDate = now()->format('Y-m-d');
        $this->entryRecognitionDate = now()->format('Y-m-d');
        $this->entryLines = [
            ['account_id' => '', 'debit_amount' => '', 'credit_amount' => '', 'description' => ''],
            ['account_id' => '', 'debit_amount' => '', 'credit_amount' => '', 'description' => ''],
        ];
        $this->showCreateModal = true;
    }

    public function addLine(): void
    {
        $this->entryLines[] = ['account_id' => '', 'debit_amount' => '', 'credit_amount' => '', 'description' => ''];
    }

    public function removeLine(int $index): void
    {
        if (count($this->entryLines) > 2) {
            unset($this->entryLines[$index]);
            $this->entryLines = array_values($this->entryLines);
        }
    }

    public function createEntry(): void
    {
        $this->authorize('accounting.manage');

        $this->validate([
            'entryDescription' => 'required|string',
            'entryTransactionDate' => 'required|date',
            'entryRecognitionDate' => 'required|date',
            'entryLines' => 'required|array|min:2',
            'entryLines.*.account_id' => 'required|exists:accounts,id',
        ]);

        $lines = [];
        foreach ($this->entryLines as $line) {
            $debit = (float) ($line['debit_amount'] ?: 0);
            $credit = (float) ($line['credit_amount'] ?: 0);
            if ($debit > 0 || $credit > 0) {
                $lines[] = [
                    'account_id' => (int) $line['account_id'],
                    'debit_amount' => $debit,
                    'credit_amount' => $credit,
                    'description' => $line['description'] ?: null,
                ];
            }
        }

        $totalDebits = round(array_sum(array_column($lines, 'debit_amount')), 2);
        $totalCredits = round(array_sum(array_column($lines, 'credit_amount')), 2);

        if ($totalDebits !== $totalCredits) {
            $this->addError('entryLines', "Entry is unbalanced: debits ({$totalDebits}) != credits ({$totalCredits})");

            return;
        }

        if (empty($lines)) {
            $this->addError('entryLines', 'At least one line must have an amount.');

            return;
        }

        $service = new AccountingService;
        $service->createJournalEntry([
            'description' => $this->entryDescription,
            'transaction_date' => $this->entryTransactionDate,
            'recognition_date' => $this->entryRecognitionDate,
            'reference_type' => 'manual',
            'lines' => $lines,
        ]);

        $this->showCreateModal = false;
        unset($this->entries);
    }

    public function postEntry(int $entryId): void
    {
        $this->authorize('accounting.manage');

        $entry = JournalEntry::findOrFail($entryId);
        $service = new AccountingService;
        $service->postEntry($entry);
        unset($this->entries);
    }

    public function voidEntry(int $entryId): void
    {
        $this->authorize('accounting.manage');

        $entry = JournalEntry::findOrFail($entryId);
        $service = new AccountingService;
        $service->voidEntry($entry);
        unset($this->entries);
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'zinc',
            'posted' => 'green',
            'void' => 'red',
            default => 'zinc',
        };
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">Journal Entries</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Double-entry accounting journal.
            </flux:text>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                New Entry
            </flux:button>
        @endif
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search entries..." icon="magnifying-glass" class="w-64" />

        <flux:select wire:model.live="statusFilter" class="w-36">
            <option value="">All Statuses</option>
            @foreach (App\Models\JournalEntry::STATUSES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live="dateFrom" type="date" class="w-40" />
        <flux:text class="text-zinc-400">to</flux:text>
        <flux:input wire:model.live="dateTo" type="date" class="w-40" />
    </div>

    {{-- Entries Table --}}
    @if ($this->entries->isEmpty())
        <flux:callout variant="outline" icon="book-open">
            <flux:callout.heading>No journal entries</flux:callout.heading>
            <flux:callout.text>Journal entries will appear here when transactions are recorded.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Date</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Entry #</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Description</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Reference</th>
                        <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Total</th>
                        @if ($this->canManage)
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach ($this->entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $entry->transaction_date?->format('M j, Y') ?? $entry->recognition_date?->format('M j, Y') ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm font-mono text-zinc-600 dark:text-zinc-400">
                                {{ $entry->entry_number }}
                            </td>
                            <td class="max-w-xs truncate px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $entry->description }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                @if ($entry->reference_type)
                                    <flux:badge size="sm" color="zinc">{{ ucfirst($entry->reference_type) }}</flux:badge>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-center text-sm">
                                <flux:badge size="sm" :color="$this->statusColor($entry->status)">
                                    {{ App\Models\JournalEntry::STATUSES[$entry->status] ?? ucfirst($entry->status) }}
                                </flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($entry->lines->sum('debit_amount'), 2) }}
                            </td>
                            @if ($this->canManage)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($entry->status === 'draft')
                                            <flux:button variant="ghost" size="sm" icon="check-circle"
                                                wire:click="postEntry({{ $entry->id }})"
                                                wire:confirm="Post this journal entry? This will make it permanent.">
                                                Post
                                            </flux:button>
                                        @endif
                                        @if ($entry->status !== 'void')
                                            <flux:button variant="ghost" size="sm" icon="x-mark" class="text-red-500"
                                                wire:click="voidEntry({{ $entry->id }})"
                                                wire:confirm="Void this journal entry?">
                                                Void
                                            </flux:button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->entries->links() }}
        </div>
    @endif

    {{-- Create Entry Modal --}}
    @if ($this->canManage)
        <flux:modal wire:model="showCreateModal" class="max-w-3xl">
            <form wire:submit="createEntry">
                <div class="space-y-4">
                    <flux:heading size="lg">Create Journal Entry</flux:heading>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Transaction Date</flux:label>
                            <flux:input wire:model="entryTransactionDate" type="date" />
                            <flux:error name="entryTransactionDate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Recognition Date</flux:label>
                            <flux:input wire:model="entryRecognitionDate" type="date" />
                            <flux:error name="entryRecognitionDate" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Description</flux:label>
                        <flux:input wire:model="entryDescription" placeholder="Journal entry description..." />
                        <flux:error name="entryDescription" />
                    </flux:field>

                    {{-- Line Items --}}
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <flux:label>Line Items</flux:label>
                            <flux:button variant="ghost" size="sm" icon="plus" wire:click="addLine" type="button">
                                Add Line
                            </flux:button>
                        </div>

                        <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="grid grid-cols-12 gap-2 text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
                                <div class="col-span-4">Account</div>
                                <div class="col-span-3">Debit</div>
                                <div class="col-span-3">Credit</div>
                                <div class="col-span-2"></div>
                            </div>

                            @foreach ($entryLines as $index => $line)
                                <div wire:key="line-{{ $index }}" class="grid grid-cols-12 items-center gap-2">
                                    <div class="col-span-4">
                                        <select wire:model="entryLines.{{ $index }}.account_id" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                                            <option value="">Select account...</option>
                                            @foreach ($this->accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-span-3">
                                        <flux:input wire:model="entryLines.{{ $index }}.debit_amount" type="number" step="0.01" min="0" placeholder="0.00" />
                                    </div>
                                    <div class="col-span-3">
                                        <flux:input wire:model="entryLines.{{ $index }}.credit_amount" type="number" step="0.01" min="0" placeholder="0.00" />
                                    </div>
                                    <div class="col-span-2 text-right">
                                        @if (count($entryLines) > 2)
                                            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeLine({{ $index }})" type="button" />
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            {{-- Totals --}}
                            <div class="grid grid-cols-12 gap-2 border-t border-zinc-200 pt-2 dark:border-zinc-700">
                                <div class="col-span-4 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">Totals:</div>
                                <div class="col-span-3 text-sm font-bold text-zinc-900 dark:text-zinc-100">
                                    ${{ number_format(collect($entryLines)->sum(fn ($l) => (float) ($l['debit_amount'] ?: 0)), 2) }}
                                </div>
                                <div class="col-span-3 text-sm font-bold text-zinc-900 dark:text-zinc-100">
                                    ${{ number_format(collect($entryLines)->sum(fn ($l) => (float) ($l['credit_amount'] ?: 0)), 2) }}
                                </div>
                                <div class="col-span-2"></div>
                            </div>
                        </div>
                        <flux:error name="entryLines" />
                    </div>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button variant="ghost" wire:click="$set('showCreateModal', false)" type="button">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">Create Entry</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
