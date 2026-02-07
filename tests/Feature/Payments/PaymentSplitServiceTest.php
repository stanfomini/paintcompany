<?php

use App\Models\CompanySetting;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Services\PaymentSplitService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->settings = CompanySetting::getInstance();
});

// --- calculateSplitAmounts Tests ---

it('calculates split amounts for even three-way split', function () {
    $service = app(PaymentSplitService::class);

    $config = [
        ['name' => 'A', 'percentage' => 33.33, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
        ['name' => 'B', 'percentage' => 33.33, 'stripe_account_id' => 'acct_2', 'increase_account_id' => null],
        ['name' => 'C', 'percentage' => 33.34, 'stripe_account_id' => 'acct_3', 'increase_account_id' => null],
    ];

    $amounts = $service->calculateSplitAmounts(100.00, $config);

    expect($amounts)->toHaveCount(3)
        ->and($amounts[0]['amount'])->toBe(33.33)
        ->and($amounts[1]['amount'])->toBe(33.33)
        ->and($amounts[2]['amount'])->toBe(33.34)
        ->and($amounts[0]['amount'] + $amounts[1]['amount'] + $amounts[2]['amount'])->toBe(100.00);
});

it('calculates split amounts for 50/50 split', function () {
    $service = app(PaymentSplitService::class);

    $config = [
        ['name' => 'A', 'percentage' => 50, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
        ['name' => 'B', 'percentage' => 50, 'stripe_account_id' => 'acct_2', 'increase_account_id' => null],
    ];

    $amounts = $service->calculateSplitAmounts(1000.00, $config);

    expect($amounts[0]['amount'])->toBe(500.00)
        ->and($amounts[1]['amount'])->toBe(500.00);
});

it('calculates split amounts for uneven split (30/20/50)', function () {
    $service = app(PaymentSplitService::class);

    $config = [
        ['name' => 'Owner', 'percentage' => 30, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
        ['name' => 'Crew', 'percentage' => 20, 'stripe_account_id' => 'acct_2', 'increase_account_id' => null],
        ['name' => 'Operating', 'percentage' => 50, 'stripe_account_id' => 'acct_3', 'increase_account_id' => null],
    ];

    $amounts = $service->calculateSplitAmounts(1000.00, $config);

    expect($amounts[0]['amount'])->toBe(300.00)
        ->and($amounts[1]['amount'])->toBe(200.00)
        ->and($amounts[2]['amount'])->toBe(500.00);
});

it('last split gets remainder to avoid rounding errors', function () {
    $service = app(PaymentSplitService::class);

    $config = [
        ['name' => 'A', 'percentage' => 33.33, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
        ['name' => 'B', 'percentage' => 33.33, 'stripe_account_id' => 'acct_2', 'increase_account_id' => null],
        ['name' => 'C', 'percentage' => 33.34, 'stripe_account_id' => 'acct_3', 'increase_account_id' => null],
    ];

    // $99.99: 33.33% = 33.33, 33.33% = 33.33, remainder = 33.33
    $amounts = $service->calculateSplitAmounts(99.99, $config);

    $total = array_sum(array_column($amounts, 'amount'));
    expect($total)->toBe(99.99);
});

// --- splitPayment Tests ---

it('creates payment split records when splitting payment', function () {
    $this->settings->update([
        'payment_split_enabled' => true,
        'payment_split_config' => [
            ['name' => 'Operating', 'percentage' => 50, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
            ['name' => 'Owner', 'percentage' => 50, 'stripe_account_id' => 'acct_2', 'increase_account_id' => null],
        ],
    ]);

    $payment = Payment::factory()->create(['amount' => 1000.00]);

    // Use partial mock to stub out actual API calls
    $service = $this->partialMock(PaymentSplitService::class);
    $service->shouldReceive('executeStripeSplits')->once();
    $service->shouldReceive('executeIncreaseSplits')->never();

    $service->splitPayment($payment);

    expect($payment->fresh()->split_status)->toBe('completed')
        ->and(PaymentSplit::count())->toBe(2)
        ->and(PaymentSplit::first()->amount)->toBe('500.00');
});

it('skips splitting when not enabled', function () {
    $this->settings->update([
        'payment_split_enabled' => false,
        'payment_split_config' => null,
    ]);

    $payment = Payment::factory()->create(['amount' => 1000.00]);

    $service = app(PaymentSplitService::class);
    $service->splitPayment($payment);

    expect(PaymentSplit::count())->toBe(0)
        ->and($payment->fresh()->split_status)->toBeNull();
});

it('retries only failed splits', function () {
    $this->settings->update([
        'payment_split_enabled' => true,
        'payment_split_config' => [
            ['name' => 'Operating', 'percentage' => 50, 'stripe_account_id' => 'acct_1', 'increase_account_id' => null],
            ['name' => 'Owner', 'percentage' => 50, 'stripe_account_id' => 'acct_2', 'increase_account_id' => null],
        ],
    ]);

    $payment = Payment::factory()->create(['amount' => 1000.00, 'split_status' => 'failed']);

    // Create one completed and one failed split
    PaymentSplit::factory()->forPayment($payment)->completed()->stripe()->create([
        'destination_name' => 'Operating',
        'amount' => 500.00,
    ]);
    $failedSplit = PaymentSplit::factory()->forPayment($payment)->failed()->stripe()->create([
        'destination_name' => 'Owner',
        'amount' => 500.00,
    ]);

    // Use partial mock to stub out actual API calls
    $service = $this->partialMock(PaymentSplitService::class);
    $service->shouldReceive('executeStripeSplits')->once();

    $service->retryFailedSplits($payment);

    // After retry, the failed split should be reset to pending (since executeStripeSplits is mocked)
    // and the payment status should be completed since no errors were thrown
    expect($payment->fresh()->split_status)->toBe('completed');
});
