<?php

use App\Models\AgentProfile;
use App\Models\CustomerProfile;
use App\Models\InternalUser;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('User type methods', function () {
    it('identifies internal users correctly', function () {
        $user = User::factory()->internal()->create();

        expect($user->isInternal())->toBeTrue()
            ->and($user->isAgent())->toBeFalse()
            ->and($user->isCustomer())->toBeFalse();
    });

    it('identifies agent users correctly', function () {
        $user = User::factory()->agent()->create();

        expect($user->isAgent())->toBeTrue()
            ->and($user->isInternal())->toBeFalse()
            ->and($user->isCustomer())->toBeFalse();
    });

    it('identifies customer users correctly', function () {
        $user = User::factory()->customer()->create();

        expect($user->isCustomer())->toBeTrue()
            ->and($user->isInternal())->toBeFalse()
            ->and($user->isAgent())->toBeFalse();
    });
});

describe('User active status', function () {
    it('identifies active users correctly', function () {
        $user = User::factory()->create(['is_active' => true]);

        expect($user->isActive())->toBeTrue();
    });

    it('identifies inactive users correctly', function () {
        $user = User::factory()->inactive()->create();

        expect($user->isActive())->toBeFalse();
    });
});

describe('User profile auto-creation', function () {
    it('creates internal user profile when internal user is created', function () {
        $user = User::factory()->internal()->create();

        expect($user->internalUser)->toBeInstanceOf(InternalUser::class)
            ->and($user->internalUser->user_id)->toBe($user->id);
    });

    it('creates agent profile when agent user is created', function () {
        $user = User::factory()->agent()->create();

        expect($user->agentProfile)->toBeInstanceOf(AgentProfile::class)
            ->and($user->agentProfile->user_id)->toBe($user->id);
    });

    it('creates customer profile when customer user is created', function () {
        $user = User::factory()->customer()->create();

        expect($user->customerProfile)->toBeInstanceOf(CustomerProfile::class)
            ->and($user->customerProfile->user_id)->toBe($user->id);
    });
});

describe('User initials', function () {
    it('returns correct initials for two-word name', function () {
        $user = User::factory()->create(['name' => 'John Doe']);

        expect($user->initials())->toBe('JD');
    });

    it('returns correct initials for single-word name', function () {
        $user = User::factory()->create(['name' => 'Admin']);

        expect($user->initials())->toBe('A');
    });

    it('returns correct initials for multi-word name', function () {
        $user = User::factory()->create(['name' => 'John Michael Doe']);

        expect($user->initials())->toBe('JM');
    });
});
