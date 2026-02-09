<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('CrewProfile Bonus Storage', function () {
    it('stores and retrieves bonus_percent', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => 5.0]);

        expect((float) $crew->crewProfile->fresh()->bonus_percent)->toBe(5.0);
    });

    it('allows null bonus_percent', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => null]);

        expect($crew->crewProfile->fresh()->bonus_percent)->toBeNull();
    });
});

describe('CrewProfile Bonus Calculation', function () {
    it('calculates bonus amount from job price', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => 5.0]);

        $bonus = $crew->crewProfile->calculateBonusAmount(10000);

        expect($bonus)->toBe(500.00);
    });

    it('returns null when no bonus set', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => null]);

        $bonus = $crew->crewProfile->calculateBonusAmount(10000);

        expect($bonus)->toBeNull();
    });

    it('returns null when bonus is zero', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => 0]);

        $bonus = $crew->crewProfile->calculateBonusAmount(10000);

        expect($bonus)->toBeNull();
    });

    it('handles fractional percentages', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => 2.5]);

        $bonus = $crew->crewProfile->calculateBonusAmount(10000);

        expect($bonus)->toBe(250.00);
    });
});

describe('CrewProfile Factory States', function () {
    it('uses withBonus factory state', function () {
        $crew = User::factory()->crew()->create();
        $crew->crewProfile->update(['bonus_percent' => 7.5]);

        expect((float) $crew->crewProfile->fresh()->bonus_percent)->toBe(7.50);
    });

    it('defaults bonus_percent to null', function () {
        $crew = User::factory()->crew()->create();

        expect($crew->crewProfile->bonus_percent)->toBeNull();
    });
});
