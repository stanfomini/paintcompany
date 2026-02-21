<?php

use App\Models\SalesTerritory;
use App\Models\TerritoryBudget;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

it('sales_territories table has all hierarchy columns', function () {
    expect(Schema::hasColumn('sales_territories', 'level'))->toBeTrue();
    expect(Schema::hasColumn('sales_territories', 'parent_id'))->toBeTrue();
    expect(Schema::hasColumn('sales_territories', 'manager_id'))->toBeTrue();
});

it('territory_team_members table exists', function () {
    expect(Schema::hasTable('territory_team_members'))->toBeTrue();
    expect(Schema::hasColumn('territory_team_members', 'territory_id'))->toBeTrue();
    expect(Schema::hasColumn('territory_team_members', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('territory_team_members', 'role'))->toBeTrue();
});

it('permission_scopes table exists', function () {
    expect(Schema::hasTable('permission_scopes'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'user_id'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'permission'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'scope_type'))->toBeTrue();
    expect(Schema::hasColumn('permission_scopes', 'scope_value'))->toBeTrue();
});

it('deals table has origin_door_knock_id', function () {
    expect(Schema::hasColumn('deals', 'origin_door_knock_id'))->toBeTrue();
});

it('territory_budgets table exists', function () {
    expect(Schema::hasTable('territory_budgets'))->toBeTrue();
});

it('compensation_scenarios table exists', function () {
    expect(Schema::hasTable('compensation_scenarios'))->toBeTrue();
});

it('door_knocker role exists with correct permissions', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    expect($knocker->hasRole('door_knocker'))->toBeTrue();
    expect($knocker->hasPermissionTo('door_knocks.record'))->toBeTrue();
    expect($knocker->hasPermissionTo('territories.view_own'))->toBeTrue();
    expect($knocker->hasPermissionTo('territories.manage_regions'))->toBeFalse();
});

it('all new permissions exist in the system', function () {
    $expectedPermissions = [
        'territories.manage_regions',
        'territories.manage_zones',
        'territories.manage_blocks',
        'territories.assign_team',
        'territories.view_all',
        'territories.view_own',
        'territories.dispatch_blocks',
        'commission.configure',
        'commission.view_team',
        'commission.view_all',
        'analytics.territory_pnl',
        'compensation.planner',
    ];

    foreach ($expectedPermissions as $perm) {
        expect(\Spatie\Permission\Models\Permission::where('name', $perm)->exists())
            ->toBeTrue("Permission '$perm' should exist");
    }
});

it('factory states work for all new models and states', function () {
    $region = SalesTerritory::factory()->region()->create();
    expect($region->level)->toBe('region');

    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    expect($zone->level)->toBe('zone');
    expect($zone->parent_id)->toBe($region->id);

    $block = SalesTerritory::factory()->block()->childOf($zone)->create();
    expect($block->level)->toBe('block');
    expect($block->parent_id)->toBe($zone->id);

    $budget = TerritoryBudget::factory()->monthly()->create(['territory_id' => $region->id]);
    expect($budget->period_type)->toBe('monthly');
});
