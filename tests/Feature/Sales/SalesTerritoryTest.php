<?php

use App\Models\SalesTerritory;
use App\Models\TerritoryOffer;
use App\Models\User;

it('can create a sales territory', function () {
    $territory = SalesTerritory::factory()->create([
        'name' => 'Downtown Zone',
    ]);

    $this->assertDatabaseHas('sales_territories', [
        'id' => $territory->id,
        'name' => 'Downtown Zone',
        'status' => 'active',
    ]);
});

it('can check if point is inside territory boundary', function () {
    // Create territory with a known square polygon around (35.0, -90.0)
    $territory = SalesTerritory::factory()->create([
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-90.01, 34.99], // SW corner [lng, lat]
                [-89.99, 34.99], // SE corner
                [-89.99, 35.01], // NE corner
                [-90.01, 35.01], // NW corner
                [-90.01, 34.99], // close polygon
            ]],
        ],
        'center_lat' => 35.0,
        'center_lng' => -90.0,
    ]);

    // Point inside the polygon
    expect($territory->containsPoint(35.0, -90.0))->toBeTrue();

    // Point outside the polygon
    expect($territory->containsPoint(36.0, -90.0))->toBeFalse();
    expect($territory->containsPoint(35.0, -88.0))->toBeFalse();
});

it('scopes to active territories', function () {
    SalesTerritory::factory()->active()->create(['name' => 'Active Zone']);
    SalesTerritory::factory()->archived()->create(['name' => 'Archived Zone']);

    $active = SalesTerritory::active()->get();

    expect($active)->toHaveCount(1);
    expect($active->first()->name)->toBe('Active Zone');
});

it('has assigned user relationship', function () {
    $user = User::factory()->internal()->create();
    $territory = SalesTerritory::factory()->assignedTo($user)->create();

    expect($territory->assignedUser)->toBeInstanceOf(User::class);
    expect($territory->assignedUser->id)->toBe($user->id);
});

it('has offers relationship', function () {
    $territory = SalesTerritory::factory()->create();
    TerritoryOffer::factory()->forTerritory($territory)->count(3)->create();

    expect($territory->offers)->toHaveCount(3);
});
