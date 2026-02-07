<?php

namespace App\Services;

use App\Jobs\SplitPaymentJob;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Transfer;
use Stripe\Webhook;

class StripePaymentService
{
    public function __construct()
    {
        Stripe::setApiKey(config('stripe.secret'));
    }

    /**
     * Create a Stripe Checkout Session for an invoice.
     *
     * @return string The checkout URL
     */
    public function createCheckoutSession(Invoice $invoice): string
    {
        $session = Session::create([
            'payment_method_types' => CompanySetting::getInstance()->stripe_ach_enabled
                ? ['card', 'us_bank_account']
                : ['card'],
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => "Invoice {$invoice->invoice_number}",
                            'description' => $invoice->notes ?? "Payment for invoice {$invoice->invoice_number}",
                        ],
                        'unit_amount' => (int) round((float) $invoice->balance_due * 100),
                    ],
                    'quantity' => 1,
                ],
            ],
            'mode' => 'payment',
            'success_url' => route('invoice.pay', ['token' => $invoice->public_token]).'?success=1',
            'cancel_url' => route('invoice.pay', ['token' => $invoice->public_token]).'?cancelled=1',
            'metadata' => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ],
        ]);

        $invoice->update([
            'stripe_checkout_session_id' => $session->id,
        ]);

        return $session->url;
    }

    /**
     * Handle Stripe webhook events.
     */
    public function handleWebhook(Request $request): void
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (SignatureVerificationException $e) {
            throw new \RuntimeException('Invalid Stripe webhook signature.');
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object),
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
            default => null,
        };
    }

    /**
     * Handle a successful checkout session.
     */
    protected function handleCheckoutSessionCompleted(object $session): void
    {
        $invoice = Invoice::where('stripe_checkout_session_id', $session->id)->first();

        if (! $invoice) {
            return;
        }

        $payment = Payment::create([
            'invoice_id' => $invoice->id,
            'company_setting_id' => $invoice->company_setting_id,
            'amount' => $session->amount_total / 100,
            'method' => Payment::METHOD_STRIPE,
            'status' => Payment::STATUS_COMPLETED,
            'stripe_payment_intent_id' => $session->payment_intent,
            'received_at' => now(),
        ]);

        $invoice->update([
            'stripe_payment_intent_id' => $session->payment_intent,
        ]);

        $invoice->recalculateAmounts();

        // Dispatch payment splitting if enabled
        if (CompanySetting::getInstance()->isSplittingEnabled()) {
            SplitPaymentJob::dispatch($payment);
        }

        // Notify customer
        $customer = $invoice->customer;
        if ($customer) {
            $customer->notify(new \App\Notifications\PaymentReceived($payment));
        }
    }

    /**
     * Handle a successful payment intent.
     */
    protected function handlePaymentIntentSucceeded(object $paymentIntent): void
    {
        // Update any pending payment with this payment intent
        $payment = Payment::where('stripe_payment_intent_id', $paymentIntent->id)
            ->where('status', Payment::STATUS_PENDING)
            ->first();

        if ($payment) {
            $payment->update(['status' => Payment::STATUS_COMPLETED]);
            $payment->invoice->recalculateAmounts();
        }
    }

    /**
     * Verify a Stripe payment by payment intent ID.
     */
    public function verifyPayment(string $paymentIntentId): bool
    {
        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);

            return $paymentIntent->status === 'succeeded';
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create a transfer to a connected Stripe account.
     */
    public function createTransfer(int $amountCents, string $destinationAccountId, string $transferGroup): Transfer
    {
        return Transfer::create([
            'amount' => $amountCents,
            'currency' => 'usd',
            'destination' => $destinationAccountId,
            'transfer_group' => $transferGroup,
        ]);
    }

    /**
     * Create a Stripe Connect account for a subcontractor or partner.
     */
    public function createConnectedAccount(string $email, string $businessName): Account
    {
        return Account::create([
            'type' => 'express',
            'country' => 'US',
            'email' => $email,
            'business_profile' => [
                'name' => $businessName,
            ],
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
        ]);
    }

    /**
     * Create an account link for Stripe Connect onboarding.
     */
    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): AccountLink
    {
        return AccountLink::create([
            'account' => $accountId,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]);
    }

    /**
     * Retrieve a connected Stripe account.
     */
    public function getAccount(string $accountId): Account
    {
        return Account::retrieve($accountId);
    }
}
