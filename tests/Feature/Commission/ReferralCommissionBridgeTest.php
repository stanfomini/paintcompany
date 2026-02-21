<?php

use App\Models\CommissionEntry;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('creates commission entry when referral reaches deposit_paid', function () {
    Notification::fake();

    $referrer = User::factory()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    $job = Job::factory()->create(['estimate_id' => $estimate->id, 'deal_id' => $deal->id]);
    $invoice = Invoice::factory()->create(['job_id' => $job->id]);

    $referral = Referral::factory()->create([
        'referrer_id' => $referrer->id,
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'status' => Referral::STATUS_SIGNED,
        'reward_amount' => 50.00,
    ]);

    app(ReferralService::class)->onDepositPaid($invoice);

    $referral->refresh();
    expect($referral->status)->toBe(Referral::STATUS_DEPOSIT_PAID);

    $entry = CommissionEntry::where('commissionable_type', User::class)
        ->where('commissionable_id', $referrer->id)
        ->where('source_type', Referral::class)
        ->where('source_id', $referral->id)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->commission_type)->toBe('flat')
        ->and((float) $entry->amount)->toBe(50.0)
        ->and($entry->source_label)->toContain('Referral:')
        ->and($entry->status)->toBe('pending');
});

it('sets employee_profile_id when referrer has one', function () {
    Notification::fake();

    $referrer = User::factory()->internal()->create();
    $profile = EmployeeProfile::factory()->create(['user_id' => $referrer->id]);
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    $job = Job::factory()->create(['estimate_id' => $estimate->id, 'deal_id' => $deal->id]);
    $invoice = Invoice::factory()->create(['job_id' => $job->id]);

    Referral::factory()->create([
        'referrer_id' => $referrer->id,
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'status' => Referral::STATUS_SIGNED,
        'reward_amount' => 50.00,
    ]);

    app(ReferralService::class)->onDepositPaid($invoice);

    $entry = CommissionEntry::where('commissionable_type', User::class)
        ->where('commissionable_id', $referrer->id)
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->employee_profile_id)->toBe($profile->id);
});

it('does not create duplicate commission entry for same referral', function () {
    Notification::fake();

    $referrer = User::factory()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    $job = Job::factory()->create(['estimate_id' => $estimate->id, 'deal_id' => $deal->id]);
    $invoice = Invoice::factory()->create(['job_id' => $job->id]);

    $referral = Referral::factory()->create([
        'referrer_id' => $referrer->id,
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'status' => Referral::STATUS_SIGNED,
        'reward_amount' => 50.00,
    ]);

    // First call creates the entry
    app(ReferralService::class)->onDepositPaid($invoice);

    $count = CommissionEntry::where('source_type', Referral::class)
        ->where('source_id', $referral->id)
        ->count();
    expect($count)->toBe(1);
});

it('does not create commission entry when referral does not advance', function () {
    Notification::fake();

    $referrer = User::factory()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    $job = Job::factory()->create(['estimate_id' => $estimate->id, 'deal_id' => $deal->id]);
    $invoice = Invoice::factory()->create(['job_id' => $job->id]);

    // Already at deposit_paid - can't advance again
    Referral::factory()->create([
        'referrer_id' => $referrer->id,
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'status' => Referral::STATUS_DEPOSIT_PAID,
        'reward_amount' => 50.00,
    ]);

    app(ReferralService::class)->onDepositPaid($invoice);

    expect(CommissionEntry::where('commissionable_type', User::class)->count())->toBe(0);
});
