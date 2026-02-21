<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('owner sees all territories on map', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $region1 = SalesTerritory::factory()->region()->create(['name' => 'Region A']);
    $region2 = SalesTerritory::factory()->region()->create(['name' => 'Region B']);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSee('Region A')
        ->assertSee('Region B');
});

it('region manager sees only their territory tree on map', function () {
    $manager = User::factory()->internal()->withRole('manager')->create();

    $myRegion = SalesTerritory::factory()->region()->managedBy($manager)->create([
        'name' => 'My Region',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-90, 35], [-89, 35], [-89, 36], [-90, 36], [-90, 35]]]],
    ]);
    $myZone = SalesTerritory::factory()->zone()->childOf($myRegion)->create([
        'name' => 'My Zone',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-90, 35], [-89.5, 35], [-89.5, 35.5], [-90, 35.5], [-90, 35]]]],
    ]);
    $otherRegion = SalesTerritory::factory()->region()->create([
        'name' => 'Other Region',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-88, 35], [-87, 35], [-87, 36], [-88, 36], [-88, 35]]]],
    ]);

    // Grant scoped view
    app(PermissionScopeService::class)->grantPermission(
        $manager,
        'territories.view_own',
        ['territory_ids' => [$myRegion->id, $myZone->id]],
        $manager
    );

    $component = Livewire::actingAs($manager)
        ->test('sales.territory-manager');

    $polygons = $component->get('territoryPolygons');
    $names = collect($polygons)->pluck('name');

    expect($names)->toContain('My Region');
    expect($names)->toContain('My Zone');
    expect($names)->not->toContain('Other Region');
});
