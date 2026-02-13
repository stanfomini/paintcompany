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

    public ?int $expandedEntryId = null;

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

    public function toggleExpand(int $entryId): void
    {
        $this->expandedEntryId = $this->expandedEntryId === $entryId ? null : $entryId;
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
            <flux:heading size="lg">{{ __('Journal Entries') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Double-entry accounting journal.') }}
            </flux:text>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                {{ __('New Entry') }}
            </flux:button>
        @endif
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.300ms="search" :placeholder="__('Search entries...')" icon="magnifying-glass" class="w-64" />

        <flux:select wire:model.live="statusFilter" class="w-36">
            <option value="">{{ __('All Statuses') }}</option>
            @foreach (App\Models\JournalEntry::STATUSES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>

        <flux:input wire:model.live="dateFrom" type="date" class="w-40" />
        <flux:text class="text-zinc-400">{{ __('to') }}</flux:text>
        <flux:input wire:model.live="dateTo" type="date" class="w-40" />
    </div>

    {{-- Entries Table --}}
    @if ($this->entries->isEmpty())
        <flux:callout variant="outline" icon="book-open">
            <flux:callout.heading>{{ __('No journal entries') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Journal entries will appear here when transactions are recorded.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Entry #') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Reference') }}</th>
                        <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</th>
                        @if ($this->canManage)
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach ($this->entries as $entry)
                        @php
                            $isExpanded = $expandedEntryId === $entry->id;
                            $totalDebits = $entry->lines->sum('debit_amount');
                            $totalCredits = $entry->lines->sum('credit_amount');
                        @endphp
                        <tr wire:key="entry-{{ $entry->id }}" wire:click="toggleExpand({{ $entry->id }})" class="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors">
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                <div class="flex items-center gap-2">
                                    <svg class="h-4 w-4 shrink-0 text-zinc-400 transition-transform {{ $isExpanded ? 'rotate-90' : '' }}" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                                    {{ $entry->transaction_date?->format('M j, Y') ?? $entry->recognition_date?->format('M j, Y') ?? '-' }}
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm font-mono text-zinc-600 dark:text-zinc-400">
                                {{ $entry->entry_number }}
                            </td>
                            <td class="max-w-xs truncate px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $entry->description }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                @if ($entry->reference_type)
                                    <flux:badge size="sm" color="zinc">{{ str_replace('_', ' ', ucfirst($entry->reference_type)) }}</flux:badge>
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
                                ${{ number_format($totalDebits, 2) }}
                            </td>
                            @if ($this->canManage)
                                <td class="whitespace-nowrap px-4 py-2 text-right" wire:click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($entry->status === 'draft')
                                            <flux:button variant="ghost" size="sm" icon="check-circle"
                                                wire:click="postEntry({{ $entry->id }})"
                                                wire:confirm="{{ __('Post this journal entry? This will make it permanent.') }}">
                                                {{ __('Post') }}
                                            </flux:button>
                                        @endif
                                        @if ($entry->status !== 'void')
                                            <flux:button variant="ghost" size="sm" icon="x-mark" class="text-red-500"
                                                wire:click="voidEntry({{ $entry->id }})"
                                                wire:confirm="{{ __('Void this journal entry?') }}">
                                                {{ __('Void') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>

                        {{-- Expanded Detail: Journal Entry Lines --}}
                        @if ($isExpanded)
                            <tr wire:key="detail-{{ $entry->id }}">
                                <td colspan="{{ $this->canManage ? 7 : 6 }}" class="bg-zinc-50/80 px-4 py-0 dark:bg-zinc-800/40">
                                    <div class="py-4 pl-6">
                                        <table class="w-full">
                                            <thead>
                                                <tr class="text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
                                                    <th class="pb-2 pr-4 text-left">{{ __('Account') }}</th>
                                                    <th class="pb-2 pr-4 text-left">{{ __('Description') }}</th>
                                                    <th class="pb-2 pr-4 text-right">{{ __('Debit') }}</th>
                                                    <th class="pb-2 text-right">{{ __('Credit') }}</th>
                                                </tr>
                                            </thead>
                                            <tbody class="text-sm">
                                                @foreach ($entry->lines->sortByDesc('debit_amount') as $line)
                                                    <tr wire:key="line-{{ $line->id }}">
                                                        <td class="py-1.5 pr-4 font-mono text-xs text-zinc-600 dark:text-zinc-400">
                                                            {{ $line->account?->code }} - {{ $line->account?->name }}
                                                        </td>
                                                        <td class="py-1.5 pr-4 text-zinc-700 dark:text-zinc-300">
                                                            {{ $line->description ?: '-' }}
                                                        </td>
                                                        <td class="py-1.5 pr-4 text-right tabular-nums {{ (float) $line->debit_amount > 0 ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-400 dark:text-zinc-600' }}">
                                                            {{ (float) $line->debit_amount > 0 ? '$'.number_format($line->debit_amount, 2) : '' }}
                                                        </td>
                                                        <td class="py-1.5 text-right tabular-nums {{ (float) $line->credit_amount > 0 ? 'text-zinc-900 dark:text-zinc-100' : 'text-zinc-400 dark:text-zinc-600' }}">
                                                            {{ (float) $line->credit_amount > 0 ? '$'.number_format($line->credit_amount, 2) : '' }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                            <tfoot>
                                                <tr class="border-t border-zinc-200 dark:border-zinc-700">
                                                    <td class="pt-2 pr-4 text-right font-medium text-zinc-900 dark:text-zinc-100" colspan="2">{{ __('Totals') }}</td>
                                                    <td class="pt-2 pr-4 text-right font-bold tabular-nums text-zinc-900 dark:text-zinc-100">${{ number_format($totalDebits, 2) }}</td>
                                                    <td class="pt-2 text-right font-bold tabular-nums text-zinc-900 dark:text-zinc-100">${{ number_format($totalCredits, 2) }}</td>
                                                </tr>
                                                @if (round((float) $totalDebits, 2) === round((float) $totalCredits, 2))
                                                    <tr>
                                                        <td colspan="4" class="pt-1 text-right">
                                                            <span class="inline-flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                                                {{ __('Balanced') }}
                                                            </span>
                                                        </td>
                                                    </tr>
                                                @else
                                                    <tr>
                                                        <td colspan="4" class="pt-1 text-right">
                                                            <span class="inline-flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                                                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                                                                {{ __('Unbalanced') }} ({{ '$'.number_format(abs($totalDebits - $totalCredits), 2) }} {{ __('difference') }})
                                                            </span>
                                                        </td>
                                                    </tr>
                                                @endif
                                            </tfoot>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        @endif
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
                    <flux:heading size="lg">{{ __('Create Journal Entry') }}</flux:heading>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>{{ __('Transaction Date') }}</flux:label>
                            <flux:input wire:model="entryTransactionDate" type="date" />
                            <flux:error name="entryTransactionDate" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('Recognition Date') }}</flux:label>
                            <flux:input wire:model="entryRecognitionDate" type="date" />
                            <flux:error name="entryRecognitionDate" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Description') }}</flux:label>
                        <flux:input wire:model="entryDescription" :placeholder="__('Journal entry description...')" />
                        <flux:error name="entryDescription" />
                    </flux:field>

                    {{-- Line Items --}}
                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <flux:label>{{ __('Line Items') }}</flux:label>
                            <flux:button variant="ghost" size="sm" icon="plus" wire:click="addLine" type="button">
                                {{ __('Add Line') }}
                            </flux:button>
                        </div>

                        <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <div class="grid grid-cols-12 gap-2 text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">
                                <div class="col-span-4">{{ __('Account') }}</div>
                                <div class="col-span-3">{{ __('Debit') }}</div>
                                <div class="col-span-3">{{ __('Credit') }}</div>
                                <div class="col-span-2"></div>
                            </div>

                            @foreach ($entryLines as $index => $line)
                                <div wire:key="line-{{ $index }}" class="grid grid-cols-12 items-center gap-2">
                                    <div class="col-span-4">
                                        <select wire:model="entryLines.{{ $index }}.account_id" class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                                            <option value="">{{ __('Select account...') }}</option>
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
                                <div class="col-span-4 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Totals:') }}</div>
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
                        <flux:button variant="ghost" wire:click="$set('showCreateModal', false)" type="button">{{ __('Cancel') }}</flux:button>
                        <flux:button type="submit" variant="primary">{{ __('Create Entry') }}</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
