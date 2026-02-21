<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\GeoLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('skips local IPs and returns null', function () {
    $service = new GeoLocationService;

    expect($service->detectFromIp('127.0.0.1'))->toBeNull();
    expect($service->detectFromIp('::1'))->toBeNull();
    expect($service->detectFromIp('192.168.1.1'))->toBeNull();
    expect($service->detectFromIp('10.0.0.1'))->toBeNull();
});

it('returns location data for a public IP', function () {
    Http::fake([
        'ip-api.com/*' => Http::response([
            'status' => 'success',
            'city' => 'Denver',
            'regionName' => 'Colorado',
            'zip' => '80202',
            'lat' => 39.7392,
            'lon' => -104.9903,
        ]),
    ]);

    $service = new GeoLocationService;
    $result = $service->detectFromIp('8.8.8.8');

    expect($result)->toBeArray()
        ->and($result['city'])->toBe('Denver')
        ->and($result['state'])->toBe('Colorado')
        ->and($result['zip'])->toBe('80202')
        ->and($result['lat'])->toBe(39.7392)
        ->and($result['lng'])->toBe(-104.9903);
});

it('finds the correct territory for coordinates', function () {
    // Create a territory with a known polygon around (39.74, -104.99)
    $territory = SalesTerritory::factory()->create([
        'level' => 'block',
        'status' => 'active',
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-105.0, 39.73],
                [-104.98, 39.73],
                [-104.98, 39.75],
                [-105.0, 39.75],
                [-105.0, 39.73],
            ]],
        ],
    ]);

    $service = new GeoLocationService;
    $result = $service->findTerritoryForCoordinates(39.74, -104.99);

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($territory->id);
});

it('returns null when no territory matches coordinates', function () {
    SalesTerritory::factory()->create([
        'level' => 'block',
        'status' => 'active',
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-105.0, 39.73],
                [-104.98, 39.73],
                [-104.98, 39.75],
                [-105.0, 39.75],
                [-105.0, 39.73],
            ]],
        ],
    ]);

    $service = new GeoLocationService;
    // Coordinates far from the territory
    $result = $service->findTerritoryForCoordinates(40.0, -100.0);

    expect($result)->toBeNull();
});

it('prefers blocks over zones when both contain point', function () {
    $zone = SalesTerritory::factory()->create([
        'name' => 'Big Zone',
        'level' => 'zone',
        'status' => 'active',
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-105.1, 39.7],
                [-104.9, 39.7],
                [-104.9, 39.8],
                [-105.1, 39.8],
                [-105.1, 39.7],
            ]],
        ],
    ]);

    $block = SalesTerritory::factory()->create([
        'name' => 'Small Block',
        'level' => 'block',
        'status' => 'active',
        'parent_id' => $zone->id,
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-105.0, 39.73],
                [-104.98, 39.73],
                [-104.98, 39.75],
                [-105.0, 39.75],
                [-105.0, 39.73],
            ]],
        ],
    ]);

    $service = new GeoLocationService;
    $result = $service->findTerritoryForCoordinates(39.74, -104.99);

    expect($result->id)->toBe($block->id);
});

it('returns null for local IPs so landing page defaults to Denver', function () {
    $service = new GeoLocationService;
    $result = $service->detectFromIp('127.0.0.1');

    expect($result)->toBeNull();
});

it('shows generic content in local-team when no territory matches', function () {
    $this->mock(GeoLocationService::class, function ($mock) {
        $mock->shouldReceive('detectFromIp')->andReturn(null);
    });

    Livewire::test('public.local-team')
        ->assertSee('Meet Your Local Team')
        ->assertSee('Serving the Denver Metro Area');
});

it('shows city-specific content when territory is found', function () {
    $manager = User::factory()->internal()->create(['name' => 'John Manager']);
    $territory = SalesTerritory::factory()->create([
        'name' => 'Lakewood Block',
        'level' => 'block',
        'status' => 'active',
        'manager_id' => $manager->id,
        'boundary' => [
            'type' => 'Polygon',
            'coordinates' => [[
                [-105.1, 39.7],
                [-104.9, 39.7],
                [-104.9, 39.8],
                [-105.1, 39.8],
                [-105.1, 39.7],
            ]],
        ],
    ]);

    $this->mock(GeoLocationService::class, function ($mock) use ($territory, $manager) {
        $mock->shouldReceive('detectFromIp')->andReturn([
            'city' => 'Lakewood',
            'state' => 'Colorado',
            'zip' => '80226',
            'lat' => 39.74,
            'lng' => -105.0,
        ]);
        $mock->shouldReceive('findTerritoryForCoordinates')->andReturn($territory);
        $mock->shouldReceive('getLocalTeam')->andReturn([
            'territory' => $territory,
            'manager' => $manager,
            'estimators' => collect(),
            'knockers' => collect(),
        ]);
    });

    Livewire::test('public.local-team')
        ->assertSee('We have a team in Lakewood!')
        ->assertSee('John Manager');
});
