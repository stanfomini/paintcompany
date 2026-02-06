<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

new #[Layout('components.layouts.customer', ['title' => 'Invoice Details'])] class extends Component
{
    public Invoice $invoice;

    public function mount(Invoice $invoice): void
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer || $invoice->customer_id !== $customer->id) {
            throw new NotFoundHttpException('Invoice not found.');
        }

        // Don't show draft invoices to customers
        if ($invoice->status === Invoice::STATUS_DRAFT) {
            throw new NotFoundHttpException('Invoice not found.');
        }

        $this->invoice = $invoice;
    }

    #[Computed]
    public function payments()
    {
        return $this->invoice->payments()
            ->where('status', Payment::STATUS_COMPLETED)
            ->latest('received_at')
            ->get();
    }

    #[Computed]
    public function paymentUrl(): ?string
    {
        if (! $this->invoice->canAcceptPayments()) {
            return null;
        }

        if (! $this->invoice->public_token || ! $this->invoice->isPublicTokenValid()) {
            return null;
        }

        return $this->invoice->getPublicUrl();
    }

    protected function statusColor(string $status): string
    {
        return match ($status) {
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
        </div>

        <div class="flex items-center gap-2">
            <flux:button variant="ghost" icon="arrow-left" href="{{ route('customer.invoices.index') }}" size="sm">
                Back
            </flux:button>

            @if ($this->paymentUrl)
                <flux:button variant="primary" icon="credit-card" href="{{ $this->paymentUrl }}" size="sm">
                    Pay Now
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
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">From</h3>
                        @if ($invoice->companySetting)
                            <p class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $invoice->companySetting->company_name }}</p>
                        @endif
                    </div>
                    <div class="text-right">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Details</h3>
                        @if ($invoice->sent_at)
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Sent: {{ $invoice->sent_at->format('M j, Y') }}
                            </p>
                        @endif
                        @if ($invoice->due_date)
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                Due: {{ $invoice->due_date->format('M j, Y') }}
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
                <flux:heading size="md" class="mb-4">Payment History</flux:heading>

                @if ($this->payments->isEmpty())
                    <flux:text class="text-zinc-400">No payments recorded yet.</flux:text>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead>
                                <tr>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Date</th>
                                    <th class="px-3 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Method</th>
                                    <th class="px-3 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Amount</th>
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
                <flux:heading size="md" class="mb-4">Summary</flux:heading>

                <div class="space-y-3">
                    <div class="flex justify-between">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">Subtotal</span>
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
                        <span class="font-medium text-zinc-900 dark:text-zinc-100">Total</span>
                        <span class="font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->total, 2) }}</span>
                    </div>

                    @if ((float) $invoice->amount_paid > 0)
                        <div class="flex justify-between">
                            <span class="text-sm text-emerald-600 dark:text-emerald-400">Paid</span>
                            <span class="text-sm font-medium text-emerald-600 dark:text-emerald-400">-${{ number_format((float) $invoice->amount_paid, 2) }}</span>
                        </div>
                    @endif

                    <flux:separator />

                    <div class="flex justify-between">
                        <span class="text-lg font-bold {{ (float) $invoice->balance_due > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">Balance Due</span>
                        <span class="text-lg font-bold {{ (float) $invoice->balance_due > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400' }}">${{ number_format((float) $invoice->balance_due, 2) }}</span>
                    </div>
                </div>
            </div>

            {{-- Pay Now CTA --}}
            @if ($this->paymentUrl)
                <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-6 dark:border-emerald-800 dark:bg-emerald-900/20">
                    <p class="mb-3 text-sm font-medium text-emerald-800 dark:text-emerald-300">Ready to pay?</p>
                    <flux:button variant="primary" icon="credit-card" href="{{ $this->paymentUrl }}" class="w-full">
                        Pay ${{ number_format((float) $invoice->balance_due, 2) }}
                    </flux:button>
                </div>
            @endif

            {{-- Terms --}}
            @if ($invoice->terms)
                <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="md" class="mb-4">Terms</flux:heading>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $invoice->terms }}</p>
                </div>
            @endif
        </div>
    </div>
</div>
