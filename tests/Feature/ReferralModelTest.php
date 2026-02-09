<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Referral;
use App\Models\User;

describe('Referral model', function () {
    it('creates a referral with factory', function () {
        $referral = Referral::factory()->create();

        expect($referral)->toBeInstanceOf(Referral::class);
        expect($referral->id)->not->toBeNull();
        expect($referral->status)->toBe(Referral::STATUS_LEAD);
    });

    it('can advance status forward', function () {
        $referral = Referral::factory()->create([
            'status' => Referral::STATUS_LEAD,
        ]);

        expect($referral->advanceTo(Referral::STATUS_QUOTED))->toBeTrue();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_QUOTED);

        expect($referral->advanceTo(Referral::STATUS_SIGNED))->toBeTrue();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_SIGNED);

        expect($referral->advanceTo(Referral::STATUS_DEPOSIT_PAID))->toBeTrue();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_DEPOSIT_PAID);

        expect($referral->advanceTo(Referral::STATUS_REWARD_SENT))->toBeTrue();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_REWARD_SENT);
    });

    it('cannot advance status backward', function () {
        $referral = Referral::factory()->create([
            'status' => Referral::STATUS_SIGNED,
        ]);

        expect($referral->advanceTo(Referral::STATUS_LEAD))->toBeFalse();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_SIGNED);

        expect($referral->advanceTo(Referral::STATUS_QUOTED))->toBeFalse();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_SIGNED);
    });

    it('cannot advance to same status', function () {
        $referral = Referral::factory()->create([
            'status' => Referral::STATUS_QUOTED,
        ]);

        expect($referral->advanceTo(Referral::STATUS_QUOTED))->toBeFalse();
        expect($referral->fresh()->status)->toBe(Referral::STATUS_QUOTED);
    });

    it('correctly reports isRewardEarned', function () {
        $lead = Referral::factory()->create(['status' => Referral::STATUS_LEAD]);
        $quoted = Referral::factory()->create(['status' => Referral::STATUS_QUOTED]);
        $signed = Referral::factory()->create(['status' => Referral::STATUS_SIGNED]);
        $depositPaid = Referral::factory()->depositPaid()->create();
        $rewardSent = Referral::factory()->rewardSent()->create();

        expect($lead->isRewardEarned())->toBeFalse();
        expect($quoted->isRewardEarned())->toBeFalse();
        expect($signed->isRewardEarned())->toBeFalse();
        expect($depositPaid->isRewardEarned())->toBeTrue();
        expect($rewardSent->isRewardEarned())->toBeTrue();
    });

    it('correctly reports isRewardSent', function () {
        $depositPaid = Referral::factory()->depositPaid()->create();
        $rewardSent = Referral::factory()->rewardSent()->create();

        expect($depositPaid->isRewardSent())->toBeFalse();
        expect($rewardSent->isRewardSent())->toBeTrue();
    });

    it('has referrer relationship', function () {
        $referrer = User::factory()->internal()->create();
        $referral = Referral::factory()->create(['referrer_id' => $referrer->id]);

        expect($referral->referrer)->toBeInstanceOf(User::class);
        expect($referral->referrer->id)->toBe($referrer->id);
    });

    it('has customer relationship', function () {
        $customer = Customer::factory()->create();
        $referral = Referral::factory()->create(['customer_id' => $customer->id]);

        expect($referral->customer)->toBeInstanceOf(Customer::class);
        expect($referral->customer->id)->toBe($customer->id);
    });

    it('has deal relationship', function () {
        $deal = Deal::factory()->create();
        $referral = Referral::factory()->create(['deal_id' => $deal->id]);

        expect($referral->deal)->toBeInstanceOf(Deal::class);
        expect($referral->deal->id)->toBe($deal->id);
    });

    it('has status label attribute', function () {
        $referral = Referral::factory()->create(['status' => Referral::STATUS_DEPOSIT_PAID]);

        expect($referral->status_label)->toBe('Deposit Paid');
    });
});
