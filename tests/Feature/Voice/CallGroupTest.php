<?php

use App\Models\CallGroup;
use App\Models\CallGroupMember;
use App\Models\User;

it('creates a call group via factory', function () {
    $group = CallGroup::factory()->create();

    expect($group)->toBeInstanceOf(CallGroup::class)
        ->and($group->name)->not->toBeEmpty()
        ->and($group->is_active)->toBeTrue()
        ->and($group->ring_timeout_seconds)->toBeGreaterThan(0);
});

it('has members relationship', function () {
    $group = CallGroup::factory()->create();
    $user = User::factory()->internal()->create(['forwarding_number' => '+15551234567']);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    expect($group->members)->toHaveCount(1)
        ->and($group->members->first()->user_id)->toBe($user->id);
});

it('has users relationship via belongsToMany', function () {
    $group = CallGroup::factory()->create();
    $user = User::factory()->internal()->create(['forwarding_number' => '+15551234567']);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'is_active' => true,
    ]);

    expect($group->users)->toHaveCount(1)
        ->and($group->users->first()->id)->toBe($user->id);
});

it('filters active groups with scope', function () {
    CallGroup::factory()->create(['is_active' => true]);
    CallGroup::factory()->create(['is_active' => false]);

    expect(CallGroup::active()->count())->toBe(1);
});

it('gets active forwarding numbers from members', function () {
    $group = CallGroup::factory()->create();

    $user1 = User::factory()->internal()->create(['forwarding_number' => '+15551111111']);
    $user2 = User::factory()->internal()->create(['forwarding_number' => '+15552222222']);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user1->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user2->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $numbers = $group->getActiveForwardingNumbers();

    expect($numbers)->toHaveCount(2)
        ->and($numbers[0])->toBe('+15551111111')
        ->and($numbers[1])->toBe('+15552222222');
});

it('excludes inactive members from forwarding numbers', function () {
    $group = CallGroup::factory()->create();

    $activeUser = User::factory()->internal()->create(['forwarding_number' => '+15551111111']);
    $inactiveUser = User::factory()->internal()->create(['forwarding_number' => '+15552222222']);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $activeUser->id,
        'is_active' => true,
    ]);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $inactiveUser->id,
        'is_active' => false,
    ]);

    $numbers = $group->getActiveForwardingNumbers();

    expect($numbers)->toHaveCount(1)
        ->and($numbers[0])->toBe('+15551111111');
});

it('uses member forwarding number override', function () {
    $group = CallGroup::factory()->create();
    $user = User::factory()->internal()->create(['forwarding_number' => '+15551111111']);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'forwarding_number' => '+15559999999',
        'is_active' => true,
    ]);

    $numbers = $group->getActiveForwardingNumbers();

    expect($numbers)->toHaveCount(1)
        ->and($numbers[0])->toBe('+15559999999');
});

it('falls back to user forwarding number when member has none', function () {
    $group = CallGroup::factory()->create();
    $user = User::factory()->internal()->create(['forwarding_number' => '+15551111111']);

    $member = CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'forwarding_number' => null,
        'is_active' => true,
    ]);

    expect($member->getForwardingNumber())->toBe('+15551111111');
});

it('returns empty array for group with no members', function () {
    $group = CallGroup::factory()->create();

    expect($group->getActiveForwardingNumbers())->toBeEmpty();
});

it('returns empty array for group where members have no numbers', function () {
    $group = CallGroup::factory()->create();
    $user = User::factory()->internal()->create(['forwarding_number' => null]);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user->id,
        'forwarding_number' => null,
        'is_active' => true,
    ]);

    expect($group->getActiveForwardingNumbers())->toBeEmpty();
});

it('respects sort order for forwarding numbers', function () {
    $group = CallGroup::factory()->create();
    $user1 = User::factory()->internal()->create(['forwarding_number' => '+15551111111']);
    $user2 = User::factory()->internal()->create(['forwarding_number' => '+15552222222']);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user1->id,
        'is_active' => true,
        'sort_order' => 2,
    ]);

    CallGroupMember::create([
        'call_group_id' => $group->id,
        'user_id' => $user2->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $numbers = $group->getActiveForwardingNumbers();

    expect($numbers[0])->toBe('+15552222222')
        ->and($numbers[1])->toBe('+15551111111');
});
