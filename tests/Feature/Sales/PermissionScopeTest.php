<?php

use App\Models\PermissionScope;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;

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

it('grants a user an additional permission via scope', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->withRole('owner')->create();
    $service = app(PermissionScopeService::class);

    // Estimator doesn't have this permission by default
    expect($user->hasPermissionTo('territories.manage_regions'))->toBeFalse();

    $service->grantPermission($user, 'territories.manage_regions', null, $admin);

    expect($service->hasPermission($user, 'territories.manage_regions'))->toBeTrue();
});

it('revokes a role permission via scope', function () {
    $user = User::factory()->internal()->withRole('manager')->create();
    $admin = User::factory()->internal()->withRole('owner')->create();
    $service = app(PermissionScopeService::class);

    // Manager has this by default via role
    expect($user->hasPermissionTo('sales_territories.view'))->toBeTrue();

    $service->revokePermission($user, 'sales_territories.view', $admin);

    expect($service->hasPermission($user, 'sales_territories.view'))->toBeFalse();
});

it('returns scope value for visibility filtering', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->create();
    $service = app(PermissionScopeService::class);

    $service->grantPermission($user, 'territories.view_own', ['territory_ids' => [1, 2, 3]], $admin);

    $scope = $service->getScope($user, 'territories.view_own');
    expect($scope)->toBe(['territory_ids' => [1, 2, 3]]);
});

it('returns null scope when no scoping exists', function () {
    $user = User::factory()->internal()->withRole('manager')->create();
    $service = app(PermissionScopeService::class);

    $scope = $service->getScope($user, 'sales_territories.view');
    expect($scope)->toBeNull();
});

it('gets all effective permissions for a user', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->create();
    $service = app(PermissionScopeService::class);

    $service->grantPermission($user, 'territories.manage_regions', null, $admin);

    $permissions = $service->getEffectivePermissions($user);

    // Should include role permissions + granted scope
    expect($permissions->pluck('permission'))->toContain('territories.manage_regions');
});

it('removes a previously granted scope', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $admin = User::factory()->internal()->create();
    $service = app(PermissionScopeService::class);

    $service->grantPermission($user, 'territories.manage_regions', null, $admin);
    expect($service->hasPermission($user, 'territories.manage_regions'))->toBeTrue();

    $service->removeScope($user, 'territories.manage_regions');
    expect($service->hasPermission($user, 'territories.manage_regions'))->toBeFalse();
});

it('gets visible territory IDs for a scoped user', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->assignedTo($user)->create();
    $block1 = SalesTerritory::factory()->block()->childOf($zone)->create();
    $block2 = SalesTerritory::factory()->block()->childOf($zone)->create();

    $service = app(PermissionScopeService::class);
    $ids = $service->getVisibleTerritoryIds($user);

    expect($ids)->toContain($zone->id, $block1->id, $block2->id);
    expect($ids)->not->toContain($region->id);
});
