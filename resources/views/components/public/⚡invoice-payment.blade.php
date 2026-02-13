<?php

use App\Models\Invoice;
use App\Services\StripePaymentService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public ?Invoice $invoice = null;
    public string $token = '';
    public bool $paymentSuccess = false;
    public bool $paymentCancelled = false;

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->invoice = Invoice::byPublicToken($token)->first();
        $this->paymentSuccess = request()->has('success');
        $this->paymentCancelled = request()->has('cancelled');

        // Refresh invoice data after Stripe redirect
        if ($this->paymentSuccess && $this->invoice) {
            $this->invoice->refresh();
        }
    }

    public function payWithStripe(): void
    {
        if (! $this->invoice || ! $this->invoice->canAcceptPayments()) {
            return;
        }

        if (! config('stripe.secret')) {
            return;
        }

        $service = new StripePaymentService;
        $checkoutUrl = $service->createCheckoutSession($this->invoice);

        $this->redirect($checkoutUrl);
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

<div class="min-h-screen bg-zinc-50 dark:bg-zinc-950">
    <div class="mx-auto max-w-2xl px-4 py-12">
        @if (! $invoice)
            <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <flux:icon name="document-text" class="mx-auto mb-4 size-12 text-zinc-400" />
                <flux:heading size="lg">{{ __('Invoice Not Found') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This invoice link is invalid or has expired.') }}</flux:text>
            </div>
        @elseif ($paymentSuccess && $invoice->isPaid())
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-8 text-center dark:border-emerald-800 dark:bg-emerald-950/30">
                <flux:icon name="check-circle" class="mx-auto mb-4 size-16 text-emerald-500" />
                <flux:heading size="xl">{{ __('Payment Successful!') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Thank you for your payment. You will receive a confirmation email shortly.') }}</flux:text>
                <div class="mt-4 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    ${{ number_format((float) $invoice->total, 2) }}
                </div>
            </div>
        @elseif ($paymentCancelled)
            <flux:callout variant="outline" icon="exclamation-triangle" class="mb-6">
                <flux:callout.heading>{{ __('Payment Cancelled') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Your payment was cancelled. You can try again when ready.') }}</flux:callout.text>
            </flux:callout>
            @include('components.public._invoice-details')
        @elseif ($invoice->isVoid())
            <div class="rounded-lg border border-red-200 bg-red-50 p-8 text-center dark:border-red-800 dark:bg-red-950/30">
                <flux:icon name="x-circle" class="mx-auto mb-4 size-12 text-red-400" />
                <flux:heading size="lg">{{ __('Invoice Voided') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This invoice has been voided and is no longer valid.') }}</flux:text>
            </div>
        @elseif ($invoice->isPaid())
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-8 text-center dark:border-emerald-800 dark:bg-emerald-950/30">
                <flux:icon name="check-circle" class="mx-auto mb-4 size-12 text-emerald-500" />
                <flux:heading size="lg">{{ __('Invoice Paid') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This invoice has been fully paid. Thank you!') }}</flux:text>
                <div class="mt-4 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    ${{ number_format((float) $invoice->total, 2) }}
                </div>
            </div>
        @else
            @include('components.public._invoice-details')
        @endif
    </div>
</div>
