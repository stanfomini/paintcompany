<?php

use App\Models\Customer;
use App\Models\Invoice;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.customer', ['title' => 'Invoices'])] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();
    }

    #[Computed]
    public function invoices()
    {
        if (! $this->customer) {
            return collect();
        }

        return Invoice::query()
            ->where('customer_id', $this->customer->id)
            ->whereNotIn('status', [Invoice::STATUS_DRAFT, Invoice::STATUS_VOID])
            ->when($this->search, fn ($q) => $q->where('invoice_number', 'like', "%{$this->search}%"))
            ->when($this->status, fn ($q) => $q->byStatus($this->status))
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function statuses(): array
    {
        return [
            'sent' => 'Pending',
            'partially_paid' => 'Partially Paid',
            'paid' => 'Paid',
            'overdue' => 'Overdue',
        ];
    }

    #[Computed]
    public function outstandingBalance(): float
    {
        if (! $this->customer) {
            return 0;
        }

        return (float) Invoice::query()
            ->where('customer_id', $this->customer->id)
            ->unpaid()
            ->sum('balance_due');
    }

    #[Computed]
    public function overdueCount(): int
    {
        if (! $this->customer) {
            return 0;
        }

        return Invoice::query()
            ->where('customer_id', $this->customer->id)
            ->byStatus(Invoice::STATUS_OVERDUE)
            ->count();
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Invoices') }}</flux:heading>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-sm text-zinc-500">{{ __('Outstanding Balance') }}</flux:text>
                <flux:heading size="lg" class="mt-1">${{ number_format($this->outstandingBalance, 2) }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-sm text-zinc-500">{{ __('Overdue Invoices') }}</flux:text>
                <flux:heading size="lg" class="mt-1 {{ $this->overdueCount > 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $this->overdueCount }}</flux:heading>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search by invoice number...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div class="w-full sm:w-48">
                <flux:select wire:model.live="status">
                    <option value="">{{ __('All Statuses') }}</option>
                    @foreach ($this->statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->invoices instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->invoices->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Invoice #') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Type') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Due Date') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Status') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Total') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Balance Due') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->invoices as $invoice)
                            <tr wire:key="invoice-{{ $invoice->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $invoice->invoice_number }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:badge size="sm" color="zinc">
                                        {{ Invoice::TYPES[$invoice->type] ?? ucfirst($invoice->type) }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $invoice->due_date?->format('M d, Y') ?? '—' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @php
                                        $statusColor = match($invoice->status) {
                                            'sent' => 'blue',
                                            'partially_paid' => 'amber',
                                            'paid' => 'green',
                                            'overdue' => 'red',
                                            default => 'zinc',
                                        };
                                        $statusLabel = match($invoice->status) {
                                            'sent' => 'Pending',
                                            'partially_paid' => 'Partially Paid',
                                            'paid' => 'Paid',
                                            'overdue' => 'Overdue',
                                            default => ucfirst($invoice->status),
                                        };
                                    @endphp
                                    <flux:badge size="sm" color="{{ $statusColor }}">
                                        {{ $statusLabel }}
                                    </flux:badge>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:text class="font-semibold">${{ number_format($invoice->total, 2) }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:text class="{{ (float) $invoice->balance_due > 0 ? 'text-red-600 dark:text-red-400 font-semibold' : '' }}">
                                        ${{ number_format($invoice->balance_due, 2) }}
                                    </flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:button variant="ghost" size="sm" icon="eye" :href="route('customer.invoices.show', $invoice)" wire:navigate>
                                        {{ __('View') }}
                                    </flux:button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->invoices->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="credit-card" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No invoices found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $status)
                        {{ __('Try adjusting your search criteria.') }}
                    @else
                        {{ __('Invoices will appear here once sent to you.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
