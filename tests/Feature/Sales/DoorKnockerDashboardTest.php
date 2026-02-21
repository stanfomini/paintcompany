<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\PermissionScopeService;
use Livewire\Livewire;

it('door knocker sees only assigned blocks on map', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $myBlock = SalesTerritory::factory()->block()->childOf($zone)->assignedTo($knocker)->create([
        'name' => 'My Block',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-90, 35], [-89, 35], [-89, 36], [-90, 36], [-90, 35]]]],
    ]);
    $otherBlock = SalesTerritory::factory()->block()->childOf($zone)->create([
        'name' => 'Other Block',
        'boundary' => ['type' => 'Polygon', 'coordinates' => [[[-88, 35], [-87, 35], [-87, 36], [-88, 36], [-88, 35]]]],
    ]);

    app(PermissionScopeService::class)->grantPermission(
        $knocker,
        'territories.view_own',
        ['territory_ids' => [$myBlock->id]],
        $knocker
    );

    $component = Livewire::actingAs($knocker)
        ->test('sales.door-knock-map');

    $polygons = $component->get('territoryPolygons');
    $names = collect($polygons)->pluck('name');

    expect($names)->toContain('My Block');
    expect($names)->not->toContain('Other Block');
});

it('door knocker sees today stats in minimal dashboard', function () {
    $knocker = User::factory()->internal()->withRole('door_knocker')->create();

    Livewire::actingAs($knocker)
        ->test('sales.door-knock-map')
        ->assertSee('Today');
});
