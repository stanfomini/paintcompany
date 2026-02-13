<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
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
    public string $statusFilter = 'all';

    #[Url]
    public string $typeFilter = 'all';

    public int $perPage = 15;

    // Create invoice modal
    public bool $showCreateModal = false;
    public string $createCustomerId = '';
    public string $createSubtotal = '';
    public string $createNotes = '';
    public string $createDueDate = '';

    public function boot(): void
    {
        if (! Auth::user()?->can('invoices.view')) {
            abort(403);
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('invoices.manage') ?? false;
    }

    #[Computed]
    public function canCreate(): bool
    {
        return Auth::user()?->can('invoices.create') ?? false;
    }

    #[Computed]
    public function canSend(): bool
    {
        return Auth::user()?->can('invoices.send') ?? false;
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::query()
            ->with(['customer', 'estimate', 'job'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('invoice_number', 'like', "%{$this->search}%")
                        ->orWhereHas('customer', function ($cq) {
                            $cq->where('first_name', 'like', "%{$this->search}%")
                                ->orWhere('last_name', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                $query->where('status', $this->statusFilter);
            })
            ->when($this->typeFilter !== 'all', function ($query) {
                $query->where('type', $this->typeFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'total_outstanding' => Invoice::unpaid()->sum('balance_due'),
            'total_overdue' => Invoice::byStatus('overdue')->sum('balance_due'),
            'paid_this_month' => Invoice::byStatus('paid')
                ->whereMonth('paid_at', now()->month)
                ->whereYear('paid_at', now()->year)
                ->sum('total'),
            'draft_count' => Invoice::byStatus('draft')->count(),
        ];
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->authorize('invoices.create');
        $this->reset(['createCustomerId', 'createSubtotal', 'createNotes', 'createDueDate']);
        $this->showCreateModal = true;
    }

    public function createInvoice(): void
    {
        $this->authorize('invoices.create');

        $this->validate([
            'createCustomerId' => 'required|exists:customers,id',
            'createSubtotal' => 'required|numeric|min:0.01',
        ]);

        $customer = Customer::findOrFail($this->createCustomerId);
        $service = new InvoiceService;
        $service->createCustomInvoice($customer, [
            'subtotal' => $this->createSubtotal,
            'notes' => $this->createNotes ?: null,
            'due_date' => $this->createDueDate ?: null,
        ]);

        $this->showCreateModal = false;
        unset($this->invoices, $this->stats);
    }

    public function sendInvoice(int $invoiceId): void
    {
        $this->authorize('invoices.send');

        $invoice = Invoice::findOrFail($invoiceId);
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            return;
        }

        $service = new InvoiceService;
        $service->sendInvoice($invoice);

        unset($this->invoices, $this->stats);
    }

    public function voidInvoice(int $invoiceId): void
    {
        $this->authorize('invoices.manage');

        $invoice = Invoice::findOrFail($invoiceId);
        $service = new InvoiceService;
        $service->markAsVoid($invoice);

        unset($this->invoices, $this->stats);
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
            'draft' => 'zinc',
            'sent' => 'blue',
            'partially_paid' => 'amber',
            'paid' => 'green',
            'void' => 'red',
            'overdue' => 'red',
            default => 'zinc',
        };
    }

    protected function typeLabel(string $type): string
    {
        return Invoice::TYPES[$type] ?? ucfirst($type);
    }
};
?>

<div>
    {{-- Stats Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Outstanding') }}</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                ${{ number_format($this->stats['total_outstanding'], 0) }}
            </div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-red-500">{{ __('Overdue') }}</div>
            <div class="mt-1 text-2xl font-bold text-red-600 dark:text-red-400">
                ${{ number_format($this->stats['total_overdue'], 0) }}
            </div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Paid This Month') }}</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                ${{ number_format($this->stats['paid_this_month'], 0) }}
            </div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Drafts') }}</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                {{ $this->stats['draft_count'] }}
            </div>
        </div>
    </div>

    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <flux:heading size="lg">{{ __('Invoices') }}</flux:heading>

        <div class="flex items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search invoices...') }}" icon="magnifying-glass" size="sm" class="w-48" />

            <flux:select wire:model.live="statusFilter" size="sm" class="w-36">
                <option value="all">{{ __('All Statuses') }}</option>
                @foreach (App\Models\Invoice::STATUSES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="typeFilter" size="sm" class="w-36">
                <option value="all">{{ __('All Types') }}</option>
                @foreach (App\Models\Invoice::TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            @if ($this->canCreate)
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                    {{ __('New Invoice') }}
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Invoice Table --}}
    @if ($this->invoices->isEmpty())
        <flux:callout variant="outline" icon="document-text">
            <flux:callout.heading>{{ __('No invoices found') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Create a new invoice or adjust your filters.') }}
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Invoice") }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Customer") }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Type") }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Status") }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Total") }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Balance") }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Due Date") }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __("Actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @foreach ($this->invoices as $invoice)
                        <tr wire:key="invoice-{{ $invoice->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                    {{ $invoice->invoice_number }}
                                </a>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $invoice->customer?->full_name ?? 'N/A' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <flux:badge size="sm" color="zinc">{{ $this->typeLabel($invoice->type) }}</flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm">
                                <flux:badge size="sm" :color="$this->statusColor($invoice->status)">
                                    {{ Invoice::STATUSES[$invoice->status] ?? ucfirst($invoice->status) }}
                                </flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                ${{ number_format((float) $invoice->total, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ (float) $invoice->balance_due > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">
                                ${{ number_format((float) $invoice->balance_due, 2) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $invoice->due_date?->format('M j, Y') ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                <div class="flex items-center justify-end gap-1">
                                    <flux:button variant="ghost" size="sm" icon="eye" href="{{ route('invoices.show', $invoice) }}" />
                                    @if ($this->canSend && $invoice->status === 'draft')
                                        <flux:button variant="ghost" size="sm" icon="paper-airplane" wire:click="sendInvoice({{ $invoice->id }})" :wire:confirm="__('Send this invoice to the customer?')" />
                                    @endif
                                    <flux:button variant="ghost" size="sm" icon="arrow-down-tray" href="{{ route('invoices.pdf', $invoice) }}" />
                                    @if ($this->canManage && ! $invoice->isPaid() && ! $invoice->isVoid())
                                        <flux:button variant="ghost" size="sm" icon="x-mark" class="text-red-500 hover:text-red-700" wire:click="voidInvoice({{ $invoice->id }})" :wire:confirm="__('Void this invoice? This cannot be undone.')" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->invoices->links() }}
        </div>
    @endif

    {{-- Create Invoice Modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Create Invoice') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Customer') }}</flux:label>
                <flux:select wire:model="createCustomerId">
                    <option value="">{{ __('Select a customer...') }}</option>
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->full_name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="createCustomerId" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Amount') }}</flux:label>
                <flux:input wire:model="createSubtotal" type="number" step="0.01" min="0.01" placeholder="0.00" />
                <flux:error name="createSubtotal" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Due Date (optional)') }}</flux:label>
                <flux:input wire:model="createDueDate" type="date" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes (optional)') }}</flux:label>
                <flux:textarea wire:model="createNotes" rows="2" placeholder="{{ __('Invoice description...') }}" />
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="createInvoice">{{ __('Create Invoice') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
