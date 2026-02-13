<?php

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\StripePaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Invoice $invoice;

    // Record payment modal
    public bool $showPaymentModal = false;
    public string $paymentAmount = '';
    public string $paymentMethod = 'check';
    public string $paymentReference = '';
    public string $paymentNotes = '';
    public string $paymentReceivedAt = '';

    public function boot(): void
    {
        if (! Auth::user()?->can('invoices.view')) {
            abort(403);
        }
    }

    public function mount(Invoice $invoice): void
    {
        $this->invoice = $invoice;
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('invoices.manage') ?? false;
    }

    #[Computed]
    public function canSend(): bool
    {
        return Auth::user()?->can('invoices.send') ?? false;
    }

    #[Computed]
    public function canRecordPayment(): bool
    {
        return Auth::user()?->can('payments.record') ?? false;
    }

    #[Computed]
    public function payments()
    {
        return $this->invoice->payments()->latest('received_at')->get();
    }

    public function sendInvoice(): void
    {
        $this->authorize('invoices.send');

        if ($this->invoice->status !== Invoice::STATUS_DRAFT) {
            return;
        }

        $service = new InvoiceService;
        $service->sendInvoice($this->invoice);
        $this->invoice->refresh();
    }

    public function openPaymentModal(): void
    {
        $this->authorize('payments.record');

        $this->paymentAmount = (string) $this->invoice->balance_due;
        $this->paymentMethod = 'check';
        $this->paymentReference = '';
        $this->paymentNotes = '';
        $this->paymentReceivedAt = now()->format('Y-m-d');
        $this->showPaymentModal = true;
    }

    public function recordPayment(): void
    {
        $this->authorize('payments.record');

        $this->validate([
            'paymentAmount' => 'required|numeric|min:0.01|max:'.(float) $this->invoice->balance_due,
            'paymentMethod' => 'required|in:stripe,check,cash,ach,other',
            'paymentReceivedAt' => 'required|date',
        ]);

        $service = new InvoiceService;
        $service->recordManualPayment($this->invoice, [
            'amount' => $this->paymentAmount,
            'method' => $this->paymentMethod,
            'reference_number' => $this->paymentReference ?: null,
            'notes' => $this->paymentNotes ?: null,
            'received_at' => $this->paymentReceivedAt,
        ]);

        $this->showPaymentModal = false;
        $this->invoice->refresh();
        unset($this->payments);
    }

    public function voidInvoice(): void
    {
        $this->authorize('invoices.manage');

        $service = new InvoiceService;
        $service->markAsVoid($this->invoice);
        $this->invoice->refresh();
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
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="lg">{{ $invoice->invoice_number }}</flux:heading>
                <flux:badge :color="$this->statusColor($invoice->status)">
                    {{ Invoice::STATUSES[$invoice->status] ?? ucfirst($invoice->status) }}
                </flux:badge>
                <flux:badge color="zinc">{{ Invoice::TYPES[$invoice->type] ?? ucfirst($invoice->type) }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                {{ $invoice->customer?->full_name ?? 'N/A' }}
                @if ($invoice->estimate)
                    &bull; Estimate #{{ $invoice->estimate->estimate_number }}
                @endif
            </flux:text>
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('invoices.index') }}" size="sm">
                {{ __('Back') }}
            </flux:button>

            @if ($this->canSend && $invoice->status === 'draft')
                <flux:button variant="primary" icon="paper-airplane" wire:click="sendInvoice" :wire:confirm="__('Send this invoice to the customer?')" size="sm">
                    {{ __('Send') }}
                </flux:button>
            @endif

            @if ($this->canRecordPayment && $invoice->canAcceptPayments())
                <flux:button variant="primary" icon="banknotes" wire:click="openPaymentModal" size="sm">
                    {{ __('Record Payment') }}
                </flux:button>
            @endif

            <flux:button variant="ghost" icon="arrow-down-tray" href="{{ route('invoices.pdf', $invoice) }}" size="sm">
                {{ __('PDF') }}
            </flux:button>

            @if ($this->canManage && ! $invoice->isPaid() && ! $invoice->isVoid())
                <flux:button variant="ghost" icon="x-mark" class="text-red-500" wire:click="voidInvoice" :wire:confirm="__('Void this invoice? This cannot be undone.')" size="sm">
                    {{ __('Void') }}
                </flux:button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Main Content --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Invoice Details Card --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Bill To') }}</h3>
                        @if ($invoice->customer)
                            <p class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $invoice->customer->full_name }}</p>
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $invoice->customer->email }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Details') }}</h3>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('Created') }}: {{ $invoice->created_at->format('M j, Y') }}
                        </p>
                        @if ($invoice->due_date)
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('Due') }}: {{ $invoice->due_date->format('M j, Y') }}
                            </p>
                        @endif
                        @if ($invoice->sent_at)
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('Sent') }}: {{ $invoice->sent_at->format('M j, Y') }}
                            </p>
                        @endif
                    </div>
                </div>

                @if ($invoice->notes)
                    <div class="mt-4 rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $invoice->notes }}</p>
                    </div>
                @endif
            </div>

            {{-- Payment History --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="md" class="mb-4">{{ __('Payment History') }}</flux:heading>

                @if ($this->payments->isEmpty())
                    <flux:text class="text-zinc-400">{{ __('No payments recorded yet.') }}</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __("Date") }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __("Method") }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __("Reference") }}</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __("Status") }}</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">{{ __("Amount") }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($this->payments as $payment)
                                    <tr wire:key="payment-{{ $payment->id }}">
                                        <td class="px-3 py-2 text-sm text-zinc-900 dark:text-zinc-100">
                                            {{ $payment->received_at->format('M j, Y') }}
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <flux:badge size="sm" color="zinc">{{ Payment::METHODS[$payment->method] ?? ucfirst($payment->method) }}</flux:badge>
                                        </td>
                                        <td class="px-3 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            {{ $payment->reference_number ?? '-' }}
                                        </td>
                                        <td class="px-3 py-2 text-sm">
                                            <flux:badge size="sm" :color="$payment->isCompleted() ? 'green' : 'amber'">
                                                {{ Payment::STATUSES[$payment->status] ?? ucfirst($payment->status) }}
                                            </flux:badge>
                                        </td>
                                        <td class="px-3 py-2 text-right text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                            ${{ number_format((float) $payment->amount, 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Sidebar --}}
        <div class="space-y-6">
            {{-- Financial Summary --}}
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <flux:heading size="md" class="mb-4">{{ __('Summary') }}</flux:heading>

                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</span>
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->subtotal, 2) }}</span>
                    </div>

                    @if ((float) $invoice->tax_rate > 0)
                        <div class="flex justify-between">
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">Tax ({{ $invoice->tax_rate }}%)</span>
                            <span class="text-sm text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->tax_amount, 2) }}</span>
                        </div>
                    @endif

                    <flux:separator />

                    <div class="flex justify-between">
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Total') }}</span>
                        <span class="font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->total, 2) }}</span>
                    </div>

                    @if ((float) $invoice->amount_paid > 0)
                        <div class="flex justify-between">
                            <span class="text-sm text-emerald-600 dark:text-emerald-400">{{ __('Paid') }}</span>
                            <span class="text-sm font-medium text-emerald-600 dark:text-emerald-400">-${{ number_format((float) $invoice->amount_paid, 2) }}</span>
                        </div>
                    @endif

                    <flux:separator />

                    <div class="flex justify-between">
                        <span class="text-lg font-bold {{ (float) $invoice->balance_due > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ __('Balance Due') }}</span>
                        <span class="text-lg font-bold {{ (float) $invoice->balance_due > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">${{ number_format((float) $invoice->balance_due, 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Related Links --}}
            @if ($invoice->estimate || $invoice->job)
                <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="md" class="mb-4">{{ __('Related') }}</flux:heading>
                    <div class="space-y-2">
                        @if ($invoice->estimate)
                            <a href="{{ route('estimates.show', $invoice->estimate) }}" class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400">
                                <flux:icon name="document-text" class="size-4" />
                                Estimate #{{ $invoice->estimate->estimate_number }}
                            </a>
                        @endif
                        @if ($invoice->job)
                            <a href="{{ route('jobs.show', $invoice->job) }}" class="flex items-center gap-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400">
                                <flux:icon name="wrench-screwdriver" class="size-4" />
                                Job #{{ $invoice->job->id }}
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Record Payment Modal --}}
    <flux:modal wire:model="showPaymentModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Record Payment') }}</flux:heading>
            <flux:text>Recording payment for {{ $invoice->invoice_number }}</flux:text>

            <flux:field>
                <flux:label>{{ __('Amount') }}</flux:label>
                <flux:input wire:model="paymentAmount" type="number" step="0.01" min="0.01" max="{{ $invoice->balance_due }}" />
                <flux:error name="paymentAmount" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Payment Method') }}</flux:label>
                <flux:select wire:model="paymentMethod">
                    @foreach (Payment::METHODS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="paymentMethod" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Reference Number (optional)') }}</flux:label>
                <flux:input wire:model="paymentReference" placeholder="{{ __('Check #, transaction ID...') }}" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Date Received') }}</flux:label>
                <flux:input wire:model="paymentReceivedAt" type="date" />
                <flux:error name="paymentReceivedAt" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes (optional)') }}</flux:label>
                <flux:textarea wire:model="paymentNotes" rows="2" placeholder="{{ __('Payment notes...') }}" />
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showPaymentModal', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="primary" wire:click="recordPayment">{{ __('Record Payment') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
