<?php

use App\Models\Activity;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ReferralEstimateSigned;
use App\Notifications\ReferralLeadCreated;
use App\Notifications\ReferralRewardEarned;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    CompanySetting::clearInstanceCache();
    $this->settings = CompanySetting::getInstance();
    $this->settings->update([
        'referral_reward_amount' => 50.00,
        'referral_program_enabled' => true,
    ]);
    CompanySetting::clearInstanceCache();

    // Ensure an internal user exists for created_by attribution
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();

    $this->service = new ReferralService;
});

describe('createFromLeadForm', function () {
    it('creates customer and deal from lead form without referral code', function () {
        $data = [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane@example.com',
            'phone' => '303-555-1234',
            'estimate_preference' => 'virtual',
        ];

        $result = $this->service->createFromLeadForm($data);

        expect($result['customer'])->toBeInstanceOf(Customer::class);
        expect($result['deal'])->toBeInstanceOf(Deal::class);
        expect($result['referral'])->toBeNull();

        expect($result['customer']->first_name)->toBe('Jane');
        expect($result['customer']->last_name)->toBe('Smith');
        expect($result['customer']->email)->toBe('jane@example.com');
        expect($result['customer']->source)->toBe('online');

        expect($result['deal']->customer_id)->toBe($result['customer']->id);
        expect($result['deal']->lead_source)->toBe('website');
        expect($result['deal']->stage)->toBe('lead');
    });

    it('creates customer, deal, and referral from lead form with valid referral code', function () {
        Notification::fake();

        $referrer = User::factory()->internal()->create();
        $referrer->getOrCreateReferralCode();

        $data = [
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'email' => 'bob@example.com',
            'phone' => '303-555-9999',
        ];

        $result = $this->service->createFromLeadForm($data, $referrer->referral_code);

        expect($result['customer'])->toBeInstanceOf(Customer::class);
        expect($result['deal'])->toBeInstanceOf(Deal::class);
        expect($result['referral'])->toBeInstanceOf(Referral::class);

        expect($result['customer']->source)->toBe('referral');
        expect($result['deal']->lead_source)->toBe('referral');

        expect($result['referral']->referrer_id)->toBe($referrer->id);
        expect($result['referral']->customer_id)->toBe($result['customer']->id);
        expect($result['referral']->deal_id)->toBe($result['deal']->id);
        expect($result['referral']->status)->toBe(Referral::STATUS_LEAD);
        expect((float) $result['referral']->reward_amount)->toBe(50.00);
    });

    it('notifies referrer when lead is created via referral', function () {
        Notification::fake();

        $referrer = User::factory()->internal()->create();
        $referrer->getOrCreateReferralCode();

        $data = [
            'first_name' => 'Alice',
            'last_name' => 'Wonder',
            'email' => 'alice@example.com',
        ];

        $this->service->createFromLeadForm($data, $referrer->referral_code);

        Notification::assertSentTo($referrer, ReferralLeadCreated::class);
    });

    it('does not create referral when program is disabled', function () {
        Notification::fake();

        $this->settings->update(['referral_program_enabled' => false]);
        CompanySetting::clearInstanceCache();

        $referrer = User::factory()->internal()->create();
        $referrer->getOrCreateReferralCode();

        $data = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ];

        $result = $this->service->createFromLeadForm($data, $referrer->referral_code);

        expect($result['referral'])->toBeNull();
        expect($result['customer']->source)->toBe('referral');
        expect(Referral::count())->toBe(0);

        Notification::assertNotSentTo($referrer, ReferralLeadCreated::class);
    });

    it('does not create referral with invalid referral code', function () {
        Notification::fake();

        $data = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
        ];

        $result = $this->service->createFromLeadForm($data, 'INVALIDCODE');

        expect($result['referral'])->toBeNull();
        expect(Referral::count())->toBe(0);
    });
});

describe('resolveReferrer', function () {
    it('resolves referrer by referral code', function () {
        $user = User::factory()->internal()->create();
        $user->getOrCreateReferralCode();

        $resolved = $this->service->resolveReferrer($user->referral_code);

        expect($resolved)->not->toBeNull();
        expect($resolved->id)->toBe($user->id);
    });

    it('returns null for invalid referral code', function () {
        $resolved = $this->service->resolveReferrer('DOESNOTEXIST');

        expect($resolved)->toBeNull();
    });
});

describe('onEstimateSigned', function () {
    it('advances referral to signed when estimate is signed', function () {
        Notification::fake();

        $referrer = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        $referral = Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'status' => Referral::STATUS_QUOTED,
            'reward_amount' => 50.00,
        ]);

        $this->service->onEstimateSigned($estimate);

        $referral->refresh();
        expect($referral->status)->toBe(Referral::STATUS_SIGNED);
    });

    it('notifies referrer when estimate is signed', function () {
        Notification::fake();

        $referrer = User::factory()->internal()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->create();

        Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'status' => Referral::STATUS_QUOTED,
            'reward_amount' => 50.00,
        ]);

        $this->service->onEstimateSigned($estimate);

        Notification::assertSentTo($referrer, ReferralEstimateSigned::class);
    });
});

describe('onDepositPaid', function () {
    it('advances referral to deposit_paid when deposit is paid', function () {
        Notification::fake();

        $referrer = $this->internalUser;
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
            'created_by' => $referrer->id,
        ]);

        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $invoice = Invoice::factory()->create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => 'deposit',
        ]);

        Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'status' => Referral::STATUS_SIGNED,
            'reward_amount' => 50.00,
        ]);

        $this->service->onDepositPaid($invoice);

        $referral = Referral::where('deal_id', $deal->id)->first();
        expect($referral->status)->toBe(Referral::STATUS_DEPOSIT_PAID);
    });

    it('notifies referrer when reward is earned', function () {
        Notification::fake();

        $referrer = $this->internalUser;
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
            'created_by' => $referrer->id,
        ]);

        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $invoice = Invoice::factory()->create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => 'deposit',
        ]);

        Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'status' => Referral::STATUS_SIGNED,
            'reward_amount' => 50.00,
        ]);

        $this->service->onDepositPaid($invoice);

        Notification::assertSentTo($referrer, ReferralRewardEarned::class);
    });

    it('creates admin task when deposit is paid', function () {
        Notification::fake();

        $referrer = $this->internalUser;
        $customer = Customer::factory()->create([
            'first_name' => 'Task',
            'last_name' => 'Customer',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->accepted()->create([
            'created_by' => $referrer->id,
        ]);

        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
        ]);

        $invoice = Invoice::factory()->create([
            'job_id' => $job->id,
            'customer_id' => $customer->id,
            'type' => 'deposit',
        ]);

        Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'status' => Referral::STATUS_SIGNED,
            'reward_amount' => 50.00,
        ]);

        $this->service->onDepositPaid($invoice);

        $task = Activity::where('type', 'task')
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->first();

        expect($task)->not->toBeNull();
        expect($task->title)->toContain('50.00');
        expect($task->title)->toContain('referral reward');
        expect($task->is_completed)->toBeFalse();
    });
});

describe('markRewardSent', function () {
    it('marks reward as sent', function () {
        $referral = Referral::factory()->depositPaid()->create();

        $result = $this->service->markRewardSent($referral, 'Sent via Venmo');

        expect($result)->toBeTrue();
        $referral->refresh();
        expect($referral->status)->toBe(Referral::STATUS_REWARD_SENT);
        expect($referral->reward_sent_at)->not->toBeNull();
        expect($referral->reward_notes)->toBe('Sent via Venmo');
    });
});

describe('getStatsForUser', function () {
    it('returns correct stats for user', function () {
        $referrer = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        // Create referrals in different statuses
        Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'status' => Referral::STATUS_LEAD,
        ]);
        Referral::factory()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'status' => Referral::STATUS_QUOTED,
        ]);
        Referral::factory()->depositPaid()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'reward_amount' => 50.00,
        ]);
        Referral::factory()->rewardSent()->create([
            'referrer_id' => $referrer->id,
            'customer_id' => $customer->id,
            'reward_amount' => 75.00,
        ]);

        $stats = $this->service->getStatsForUser($referrer);

        expect($stats['total'])->toBe(4);
        expect($stats['leads'])->toBe(1);
        expect($stats['quoted'])->toBe(1);
        expect($stats['signed'])->toBe(0);
        expect($stats['deposit_paid'])->toBe(1);
        expect($stats['reward_sent'])->toBe(1);
        expect((float) $stats['total_earned'])->toBe(125.00);
        expect((float) $stats['pending_rewards'])->toBe(50.00);
    });
});
