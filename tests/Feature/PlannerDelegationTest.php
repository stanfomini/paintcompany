<?php

use App\Models\Activity;
use App\Models\PlannerDelegation;
use App\Models\User;
use App\Services\PlannerDelegationService;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('PlannerDelegationService', function () {
    it('creates a delegation when grantor has planner.delegate', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);
        $delegation = $service->createDelegation(
            grantor: $manager,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'act',
            isPermanent: true,
        );

        expect($delegation)->toBeInstanceOf(PlannerDelegation::class)
            ->and($delegation->access_level)->toBe('act')
            ->and($delegation->is_permanent)->toBeTrue();
    });

    it('allows user to delegate own planner with planner.delegate_own', function () {
        $user = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);
        $delegation = $service->createDelegation(
            grantor: $user,
            targetUser: $user,
            delegate: $delegate,
            accessLevel: 'view',
            isPermanent: true,
        );

        expect($delegation->target_user_id)->toBe($user->id)
            ->and($delegation->delegate_id)->toBe($delegate->id);
    });

    it('rejects delegation when grantor lacks permission', function () {
        $user = User::factory()->internal()->withRole('door_knocker')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);

        $service->createDelegation(
            grantor: $user,
            targetUser: $target,
            delegate: $delegate,
            accessLevel: 'view',
            isPermanent: true,
        );
    })->throws(AuthorizationException::class);

    it('rejects self-delegation', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $user = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);

        $service->createDelegation(
            grantor: $manager,
            targetUser: $user,
            delegate: $user,
            accessLevel: 'view',
            isPermanent: true,
        );
    })->throws(\InvalidArgumentException::class);

    it('updates existing delegation instead of creating duplicate', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);

        $first = $service->createDelegation(
            grantor: $manager, targetUser: $target, delegate: $delegate,
            accessLevel: 'view', isPermanent: true,
        );

        $second = $service->createDelegation(
            grantor: $manager, targetUser: $target, delegate: $delegate,
            accessLevel: 'full', isPermanent: true,
        );

        expect($first->id)->toBe($second->id)
            ->and($second->access_level)->toBe('full')
            ->and(PlannerDelegation::count())->toBe(1);
    });

    it('revokes a delegation', function () {
        $manager = User::factory()->internal()->withRole('manager')->create();
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        $service = app(PlannerDelegationService::class);
        $delegation = $service->createDelegation(
            grantor: $manager, targetUser: $target, delegate: $delegate,
            accessLevel: 'view', isPermanent: true,
        );

        $service->revokeDelegation($delegation->id, $manager);

        expect(PlannerDelegation::count())->toBe(0);
    });

    it('checks canActOnBehalf correctly', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'act',
            'is_permanent' => true,
        ]);

        $service = app(PlannerDelegationService::class);

        expect($service->canActOnBehalf($delegate, $target, 'view'))->toBeTrue()
            ->and($service->canActOnBehalf($delegate, $target, 'act'))->toBeTrue()
            ->and($service->canActOnBehalf($delegate, $target, 'full'))->toBeFalse();
    });

    it('returns false for expired delegations', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->expired()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'full',
        ]);

        $service = app(PlannerDelegationService::class);

        expect($service->canActOnBehalf($delegate, $target, 'view'))->toBeFalse();
    });

    it('expires stale delegations', function () {
        $target = User::factory()->internal()->create();

        PlannerDelegation::factory()->expired()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
        ]);

        PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
        ]);

        $service = app(PlannerDelegationService::class);
        $deleted = $service->expireStale();

        expect($deleted)->toBe(1)
            ->and(PlannerDelegation::count())->toBe(1);
    });

    it('returns delegated users for delegate', function () {
        $delegate = User::factory()->internal()->withRole('estimator')->create();
        $target1 = User::factory()->internal()->create();
        $target2 = User::factory()->internal()->create();

        PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $target1->id,
            'target_user_id' => $target1->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'act',
        ]);

        PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $target2->id,
            'target_user_id' => $target2->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'view',
        ]);

        $service = app(PlannerDelegationService::class);
        $delegations = $service->getDelegationsFor($delegate);

        expect($delegations)->toHaveCount(2);
    });
});

describe('Planner delegation actions', function () {
    it('allows delegate with act access to toggle complete on target users activity', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->actAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $target->id,
            'assigned_to' => $target->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue()
            ->and($activity->acted_by)->toBe($delegate->id);
    });

    it('denies delegate with view-only access from toggling complete', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->viewAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $target->id,
            'assigned_to' => $target->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    it('allows delegate with act access to set priority', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->actAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $target->id,
            'assigned_to' => $target->id,
            'priority' => 'normal',
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->call('setPriority', $activity->id, 'high');

        expect($activity->fresh()->priority)->toBe('high');
    });

    it('allows delegate with act access to snooze', function () {
        $target = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->actAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $target->id,
            'assigned_to' => $target->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->call('snoozeQuick', $activity->id, 'tomorrow');

        expect($activity->fresh()->snoozed_until)->not->toBeNull();
    });
});

describe('Delegation manager UI', function () {
    it('shows access button for users with planner.delegate_own', function () {
        $user = User::factory()->internal()->withRole('estimator')->create();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Access');
    });

    it('can create a delegation via the manager component', function () {
        $user = User::factory()->internal()->withRole('estimator')->create();
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        Livewire::actingAs($user)
            ->test('planner.delegation-manager')
            ->set('showCreateForm', true)
            ->set('newDelegateId', $delegate->id)
            ->set('newAccessLevel', 'act')
            ->set('newTimeframe', 'permanent')
            ->call('saveDelegation')
            ->assertHasNoErrors();

        expect(PlannerDelegation::where('target_user_id', $user->id)
            ->where('delegate_id', $delegate->id)
            ->where('access_level', 'act')
            ->exists())->toBeTrue();
    });

    it('can revoke a delegation via the manager component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $delegate = User::factory()->internal()->create();

        $delegation = PlannerDelegation::factory()->permanent()->create([
            'grantor_id' => $user->id,
            'target_user_id' => $user->id,
            'delegate_id' => $delegate->id,
            'access_level' => 'view',
        ]);

        Livewire::actingAs($user)
            ->test('planner.delegation-manager')
            ->call('revoke', $delegation->id);

        expect(PlannerDelegation::count())->toBe(0);
    });

    it('shows user selector when delegate has delegations', function () {
        $target = User::factory()->internal()->withRole('estimator')->create(['name' => 'Target User']);
        $delegate = User::factory()->internal()->withRole('estimator')->create();

        PlannerDelegation::factory()->actAccess()->permanent()->create([
            'grantor_id' => $target->id,
            'target_user_id' => $target->id,
            'delegate_id' => $delegate->id,
        ]);

        Livewire::actingAs($delegate)
            ->test('planner.my-day')
            ->assertSee('Target User');
    });
});
