<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('renders the hierarchy tree', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $region = SalesTerritory::factory()->region()->create(['name' => 'North Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create(['name' => 'Zone Alpha']);
    $block = SalesTerritory::factory()->block()->childOf($zone)->create(['name' => 'Block 101']);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->assertSee('North Region')
        ->assertSee('Zone Alpha')
        ->assertSee('Block 101');
});

it('creates a child zone under a region', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('startCreateChild', $region->id, 'zone')
        ->assertSet('creatingChildOfId', $region->id)
        ->assertSet('childLevel', 'zone')
        ->set('childName', 'New Zone')
        ->call('saveChild')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'New Zone',
        'level' => 'zone',
        'parent_id' => $region->id,
    ]);
});

it('creates a child block under a zone', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('startCreateChild', $zone->id, 'block')
        ->set('childName', 'New Block')
        ->call('saveChild')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'New Block',
        'level' => 'block',
        'parent_id' => $zone->id,
    ]);
});

it('assigns a user to a territory', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $assignee = User::factory()->internal()->create();
    $zone = SalesTerritory::factory()->zone()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('assignUser', $zone->id, $assignee->id);

    expect($zone->fresh()->assigned_user_id)->toBe($assignee->id);
});

it('creates a region from the hierarchy page', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('startCreateRegion')
        ->assertSet('childLevel', 'region')
        ->assertSet('showCreateModal', true)
        ->set('childName', 'West Region')
        ->call('saveChild')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'West Region',
        'level' => 'region',
        'parent_id' => null,
    ]);
});

it('adds a team member to a territory', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();
    $block = SalesTerritory::factory()->block()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('startAddTeamMember', $block->id)
        ->assertSet('assigningTerritoryId', $block->id)
        ->set('selectedUserId', $knocker->id)
        ->set('teamRole', 'knocker')
        ->call('addTeamMember')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('territory_team_members', [
        'territory_id' => $block->id,
        'user_id' => $knocker->id,
        'role' => 'knocker',
    ]);
});

it('removes a team member from a territory', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();
    $block = SalesTerritory::factory()->block()->create();
    $block->teamMembers()->attach($knocker->id, ['role' => 'knocker', 'added_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('removeTeamMember', $block->id, $knocker->id);

    $this->assertDatabaseMissing('territory_team_members', [
        'territory_id' => $block->id,
        'user_id' => $knocker->id,
    ]);
});

it('assigns a manager to a region', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $manager = User::factory()->internal()->withRole('manager')->create();
    $region = SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->call('assignManager', $region->id, $manager->id);

    expect($region->fresh()->manager_id)->toBe($manager->id);
});

it('shows only visible territories for scoped user', function () {
    $regionManager = User::factory()->internal()->withRole('manager')->create();

    $region = SalesTerritory::factory()->region()->managedBy($regionManager)->create(['name' => 'My Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create(['name' => 'My Zone']);
    $otherRegion = SalesTerritory::factory()->region()->create(['name' => 'Other Region']);

    // Grant scoped permission
    app(PermissionScopeService::class)->grantPermission(
        $regionManager,
        'territories.view_own',
        ['territory_ids' => [$region->id, $zone->id]],
        $regionManager
    );

    Livewire::actingAs($regionManager)
        ->test('sales.territory-hierarchy')
        ->assertSee('My Region')
        ->assertDontSee('Other Region');
});
