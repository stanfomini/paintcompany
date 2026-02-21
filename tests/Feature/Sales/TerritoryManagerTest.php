<?php

use App\Models\SalesTerritory;
use App\Models\User;
use Livewire\Livewire;

it('renders territory manager with tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Map')
        ->assertSee('Hierarchy')
        ->assertSee('Schedule')
        ->assertSee('Analytics')
        ->assertSet('activeTab', 'map');
});

it('switches between tabs', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'hierarchy')
        ->assertSet('activeTab', 'hierarchy');
});

it('hides schedule tab without schedule permission', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Schedule');
});

it('hides analytics tab without analytics permission', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Analytics');
});

it('saves territory with level and parent_id', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('name', 'Test Zone')
        ->set('level', 'zone')
        ->set('parentId', $region->id)
        ->set('color', '#FF0000')
        ->call('saveTerritory')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'Test Zone',
        'level' => 'zone',
        'parent_id' => $region->id,
    ]);
});

it('requires parent for zone level', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('name', 'Test Zone')
        ->set('level', 'zone')
        ->set('color', '#FF0000')
        ->call('saveTerritory')
        ->assertHasErrors(['parentId']);
});

it('does not require parent for region level', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('name', 'Test Region')
        ->set('level', 'region')
        ->set('color', '#FF0000')
        ->call('saveTerritory')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'Test Region',
        'level' => 'region',
        'parent_id' => null,
    ]);
});

it('validates parent level matches child expectation', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();

    // Block's parent must be a zone, not a region
    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('name', 'Test Block')
        ->set('level', 'block')
        ->set('parentId', $region->id)
        ->set('color', '#FF0000')
        ->call('saveTerritory')
        ->assertHasErrors(['parentId']);
});

it('filters territory polygons by level', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    SalesTerritory::factory()->region()->create(['name' => 'Region A']);
    SalesTerritory::factory()->zone()->create(['name' => 'Zone A']);
    SalesTerritory::factory()->block()->create(['name' => 'Block A']);

    $component = Livewire::actingAs($user)
        ->test('sales.territory-manager');

    // All levels visible by default
    expect($component->instance()->territoryPolygons)->toHaveCount(3);

    // Toggle off zone and block to show only regions
    $component->call('toggleLevel', 'zone')
        ->call('toggleLevel', 'block');
    expect($component->instance()->territoryPolygons)->toHaveCount(1);
    expect($component->instance()->territoryPolygons[0]['name'])->toBe('Region A');
});

it('toggles individual map levels on and off', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    SalesTerritory::factory()->region()->create(['name' => 'Region A']);
    SalesTerritory::factory()->zone()->create(['name' => 'Zone A']);
    SalesTerritory::factory()->block()->create(['name' => 'Block A']);

    $component = Livewire::actingAs($user)
        ->test('sales.territory-manager');

    // All three visible initially
    expect($component->instance()->territoryPolygons)->toHaveCount(3);
    expect($component->get('visibleLevels'))->toBe(['region', 'zone', 'block']);

    // Toggle off zones
    $component->call('toggleLevel', 'zone');
    expect($component->get('visibleLevels'))->toBe(['region', 'block']);
    expect($component->instance()->territoryPolygons)->toHaveCount(2);

    // Toggle zones back on
    $component->call('toggleLevel', 'zone');
    expect($component->get('visibleLevels'))->toContain('zone');
    expect($component->instance()->territoryPolygons)->toHaveCount(3);
});

it('loads level and parent when editing territory', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->call('editTerritory', $zone->id)
        ->assertSet('level', 'zone')
        ->assertSet('parentId', $region->id)
        ->assertSet('name', $zone->name);
});

it('shows available parents based on selected level', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create(['name' => 'My Region']);
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create(['name' => 'My Zone']);

    $component = Livewire::actingAs($user)
        ->test('sales.territory-manager');

    // Zone level should show regions as available parents
    $component->set('level', 'zone');
    $parents = $component->instance()->availableParents;
    expect($parents)->toHaveCount(1);
    expect($parents->first()->name)->toBe('My Region');

    // Block level should show zones as available parents
    $component->set('level', 'block');
    $parents = $component->instance()->availableParents;
    expect($parents)->toHaveCount(1);
    expect($parents->first()->name)->toBe('My Zone');
});

it('resets parentId when level changes', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $region = SalesTerritory::factory()->region()->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('level', 'zone')
        ->set('parentId', $region->id)
        ->assertSet('parentId', $region->id)
        ->set('level', 'region')
        ->assertSet('parentId', null);
});

it('allows block-only user to see new block button', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('New Block');
});

it('auto-sets level to block for block-only users', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    // Make zone visible to user via team membership
    $zone->teamMembers()->attach($user->id, ['role' => 'estimator', 'added_by' => $user->id]);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->call('createTerritory')
        ->assertSet('level', 'block')
        ->assertSet('parentId', $zone->id);
});

it('enforces block level in saveTerritory for block-only users', function () {
    $user = User::factory()->internal()->withRole('estimator')->create();
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('name', 'My New Block')
        ->set('level', 'block')
        ->set('parentId', $zone->id)
        ->set('color', '#00FF00')
        ->call('saveTerritory')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('sales_territories', [
        'name' => 'My New Block',
        'level' => 'block',
        'parent_id' => $zone->id,
    ]);
});

it('owner sees New Territory label not New Block', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('New Territory')
        ->assertDontSee('New Block');
});

it('includes assigned user in territory polygon data', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    $assignee = User::factory()->internal()->create(['name' => 'Jane Manager']);
    SalesTerritory::factory()->region()->create([
        'name' => 'North Region',
        'assigned_user_id' => $assignee->id,
    ]);

    $component = Livewire::actingAs($user)
        ->test('sales.territory-manager');

    $polygons = $component->instance()->territoryPolygons;
    $region = collect($polygons)->firstWhere('name', 'North Region');

    expect($region)->not->toBeNull();
    expect($region['assigned_user'])->toBe('Jane Manager');
    expect($region['level_label'])->toBe('Region');
});

it('returns null assigned_user when territory has no assignment', function () {
    $user = User::factory()->internal()->withRole('owner')->create();
    SalesTerritory::factory()->block()->create(['name' => 'Unassigned Block']);

    $component = Livewire::actingAs($user)
        ->test('sales.territory-manager');

    $polygons = $component->instance()->territoryPolygons;
    $block = collect($polygons)->firstWhere('name', 'Unassigned Block');

    expect($block)->not->toBeNull();
    expect($block['assigned_user'])->toBeNull();
    expect($block['level_label'])->toBe('Block');
});

it('hides hierarchy tab for users without hierarchy permissions', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertDontSee('Hierarchy');
});

it('redirects to map tab when unauthorized user sets hierarchy tab', function () {
    $user = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->set('activeTab', 'hierarchy')
        ->assertSet('activeTab', 'map');
});

it('shows hierarchy tab for users with hierarchy permissions', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Hierarchy')
        ->set('activeTab', 'hierarchy')
        ->assertSet('activeTab', 'hierarchy');
});

it('shows schedule tab for users with dispatch_blocks permission', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Schedule')
        ->set('activeTab', 'schedule')
        ->assertSet('activeTab', 'schedule');
});
