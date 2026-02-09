<?php

use App\Models\Referral;
use App\Models\User;

describe('User referral features', function () {
    it('generates an 8-character referral code', function () {
        $user = User::factory()->internal()->create();

        expect($user->referral_code)->toBeNull();

        $code = $user->getOrCreateReferralCode();

        expect($code)->toHaveLength(8);
        expect($code)->toBe(strtoupper($code));
        expect($user->fresh()->referral_code)->toBe($code);
    });

    it('reuses existing referral code', function () {
        $user = User::factory()->internal()->create();

        $code1 = $user->getOrCreateReferralCode();
        $code2 = $user->getOrCreateReferralCode();

        expect($code1)->toBe($code2);
    });

    it('generates a full referral link URL', function () {
        $user = User::factory()->internal()->create();

        $link = $user->referralLink();

        expect($link)->toContain('?ref=');
        expect($link)->toContain($user->referral_code);
        expect($link)->toStartWith(config('app.url'));
    });

    it('has referrals relationship', function () {
        $user = User::factory()->internal()->create();

        Referral::factory()->count(3)->create(['referrer_id' => $user->id]);

        expect($user->referrals)->toHaveCount(3);
        expect($user->referrals->first())->toBeInstanceOf(Referral::class);
    });

    it('generates unique referral codes for different users', function () {
        $user1 = User::factory()->internal()->create();
        $user2 = User::factory()->internal()->create();

        $code1 = $user1->getOrCreateReferralCode();
        $code2 = $user2->getOrCreateReferralCode();

        expect($code1)->not->toBe($code2);
    });
});
