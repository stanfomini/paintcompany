<?php

use App\Models\PlannerDelegation;
use App\Models\User;
use App\Services\PlannerDelegationService;
use Illuminate\Auth\Access\AuthorizationException;

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
