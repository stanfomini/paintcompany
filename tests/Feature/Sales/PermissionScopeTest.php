<?php

use App\Models\PermissionScope;
use App\Models\User;

it('creates a grant permission scope', function () {
    $user = User::factory()->internal()->create();
    $admin = User::factory()->internal()->create();

    $scope = PermissionScope::create([
        'user_id' => $user->id,
        'permission' => 'territories.manage_regions',
        'scope_type' => 'grant',
        'scope_value' => null,
        'granted_by' => $admin->id,
    ]);

    expect($scope->isGrant())->toBeTrue();
    expect($scope->isRevoke())->toBeFalse();
    expect($scope->user->id)->toBe($user->id);
    expect($scope->grantedBy->id)->toBe($admin->id);
});

it('creates a revoke permission scope', function () {
    $user = User::factory()->internal()->create();

    $scope = PermissionScope::create([
        'user_id' => $user->id,
        'permission' => 'door_knocks.view_all',
        'scope_type' => 'revoke',
        'granted_by' => null,
    ]);

    expect($scope->isRevoke())->toBeTrue();
});

it('creates a scope with territory visibility restriction', function () {
    $user = User::factory()->internal()->create();

    $scope = PermissionScope::create([
        'user_id' => $user->id,
        'permission' => 'territories.view_own',
        'scope_type' => 'grant',
        'scope_value' => ['territory_ids' => [1, 5, 12]],
    ]);

    expect($scope->scope_value)->toBe(['territory_ids' => [1, 5, 12]]);
});

it('gets all permission scopes for a user', function () {
    $user = User::factory()->internal()->create();

    PermissionScope::create(['user_id' => $user->id, 'permission' => 'perm.a', 'scope_type' => 'grant']);
    PermissionScope::create(['user_id' => $user->id, 'permission' => 'perm.b', 'scope_type' => 'revoke']);

    expect($user->permissionScopes)->toHaveCount(2);
});
