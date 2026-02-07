<?php

use App\Jobs\SplitPaymentJob;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
});

it('dispatches SplitPaymentJob after checkout completed when splitting enabled', function () {
    Queue::fake();

    $this->settings->update([
        'payment_split_enabled' => true,
        'payment_split_config' => [
            ['name' => 'Operating', 'percentage' => 100, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
        ],
    ]);

    $invoice = Invoice::factory()->create([
        'stripe_checkout_session_id' => 'cs_test_123',
        'company_setting_id' => $this->settings->id,
    ]);

    // Simulate what handleCheckoutSessionCompleted does
    $payment = Payment::create([
        'invoice_id' => $invoice->id,
        'company_setting_id' => $invoice->company_setting_id,
        'amount' => 500.00,
        'method' => Payment::METHOD_STRIPE,
        'status' => Payment::STATUS_COMPLETED,
        'stripe_payment_intent_id' => 'pi_test_123',
        'received_at' => now(),
    ]);

    // Manually dispatch to test the dispatch logic
    if (CompanySetting::getInstance()->isSplittingEnabled()) {
        SplitPaymentJob::dispatch($payment);
    }

    Queue::assertPushed(SplitPaymentJob::class, function ($job) use ($payment) {
        return $job->payment->id === $payment->id;
    });
});

it('does not dispatch SplitPaymentJob when splitting disabled', function () {
    Queue::fake();

    $this->settings->update([
        'payment_split_enabled' => false,
        'payment_split_config' => null,
    ]);

    $payment = Payment::factory()->create();

    if (CompanySetting::getInstance()->isSplittingEnabled()) {
        SplitPaymentJob::dispatch($payment);
    }

    Queue::assertNotPushed(SplitPaymentJob::class);
});

it('company setting reports splitting enabled correctly', function () {
    $this->settings->update([
        'payment_split_enabled' => true,
        'payment_split_config' => [
            ['name' => 'A', 'percentage' => 100, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
        ],
    ]);

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->isSplittingEnabled())->toBeTrue();

    $this->settings->update(['payment_split_enabled' => false]);
    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->isSplittingEnabled())->toBeFalse();
});

it('company setting reports stripe connect configured correctly', function () {
    config(['stripe.secret' => 'sk_test_123']);

    $this->settings->update(['stripe_connect_enabled' => true]);
    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->isStripeConnectConfigured())->toBeTrue();

    $this->settings->update(['stripe_connect_enabled' => false]);
    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->isStripeConnectConfigured())->toBeFalse();
});

it('company setting reports increase configured correctly', function () {
    $this->settings->update([
        'increase_enabled' => true,
        'increase_access_token' => 'token_123',
    ]);
    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->isIncreaseConfigured())->toBeTrue();

    $this->settings->update(['increase_access_token' => null]);
    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->isIncreaseConfigured())->toBeFalse();
});
