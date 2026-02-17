{{-- Invoice Details Partial (used in public invoice-payment page) --}}
<div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
    {{-- Invoice Header --}}
    <div class="border-b border-zinc-200 p-6 dark:border-zinc-700">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ __('Invoice') }}</h1>
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $invoice->invoice_number }}</p>
            </div>
            <flux:badge size="lg" :color="$this->statusColor($invoice->status)">
                {{ \App\Models\Invoice::STATUSES[$invoice->status] ?? ucfirst($invoice->status) }}
            </flux:badge>
        </div>
    </div>

    {{-- Details --}}
    <div class="grid grid-cols-2 gap-6 p-6">
        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('From') }}</h3>
            <p class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">
                {{ $invoice->companySetting?->company_name ?? config('app.name') }}
            </p>
        </div>
        <div class="text-right">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Due Date') }}</h3>
            <p class="mt-1 text-zinc-900 dark:text-zinc-100">
                {{ $invoice->due_date?->format('M j, Y') ?? __('Upon Receipt') }}
            </p>
        </div>
    </div>

    @if ($invoice->notes)
        <div class="px-6 pb-4">
            <p class="rounded-md bg-zinc-50 p-3 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                {{ $invoice->notes }}
            </p>
        </div>
    @endif

    {{-- Amount Summary --}}
    <div class="border-t border-zinc-200 p-6 dark:border-zinc-700">
        <div class="space-y-2">
            <div class="flex justify-between text-sm">
                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Subtotal') }}</span>
                <span class="text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->subtotal, 2) }}</span>
            </div>
            @if ((float) $invoice->tax_rate > 0)
                <div class="flex justify-between text-sm">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Tax') }} ({{ $invoice->tax_rate }}%)</span>
                    <span class="text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->tax_amount, 2) }}</span>
                </div>
            @endif
            <div class="flex justify-between border-t border-zinc-200 pt-2 dark:border-zinc-700">
                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Total') }}</span>
                <span class="font-bold text-zinc-900 dark:text-zinc-100">${{ number_format((float) $invoice->total, 2) }}</span>
            </div>
            @if ((float) $invoice->amount_paid > 0)
                <div class="flex justify-between text-sm">
                    <span class="text-emerald-600">{{ __('Amount Paid') }}</span>
                    <span class="text-emerald-600">-${{ number_format((float) $invoice->amount_paid, 2) }}</span>
                </div>
            @endif
        </div>

        <div class="mt-4 rounded-lg bg-blue-50 p-4 text-center dark:bg-blue-950/30">
            <p class="text-sm text-blue-600 dark:text-blue-400">{{ __('Balance Due') }}</p>
            <p class="text-3xl font-bold text-blue-700 dark:text-blue-300">
                ${{ number_format((float) $invoice->balance_due, 2) }}
            </p>
        </div>
    </div>

    {{-- Pay Button --}}
    @if ($invoice->canAcceptPayments() && config('stripe.secret'))
        <div class="border-t border-zinc-200 p-6 dark:border-zinc-700">
            <flux:button variant="primary" class="w-full" wire:click="payWithStripe" wire:loading.attr="disabled">
                <span wire:loading.remove>{{ __('Pay') }} ${{ number_format((float) $invoice->balance_due, 2) }} {{ __('Now') }}</span>
                <span wire:loading>{{ __('Redirecting to payment...') }}</span>
            </flux:button>
            <p class="mt-2 text-center text-xs text-zinc-400">
                {{ __('Secure payment processed by Stripe') }}
            </p>
        </div>
    @endif
</div>
