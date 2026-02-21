<?php

use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\TerritoryPnlService;
use Livewire\Livewire;

it('loads hierarchy tree with 5 regions, 20 zones, 100 blocks in under 2 seconds', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    // Create realistic data volume
    $regions = SalesTerritory::factory()->region()->count(5)->create();
    foreach ($regions as $region) {
        $zones = SalesTerritory::factory()->zone()->childOf($region)->count(4)->create();
        foreach ($zones as $zone) {
            SalesTerritory::factory()->block()->childOf($zone)->count(5)->create();
        }
    }

    $start = microtime(true);

    Livewire::actingAs($user)
        ->test('sales.territory-hierarchy')
        ->assertSuccessful();

    $elapsed = microtime(true) - $start;
    expect($elapsed)->toBeLessThan(2.0, "Hierarchy loaded in {$elapsed}s, expected < 2s");
});

it('calculates P&L rollup for region with 20 zones in under 3 seconds', function () {
    $region = SalesTerritory::factory()->region()->create();
    SalesTerritory::factory()->zone()->childOf($region)->count(20)->create();

    $start = microtime(true);

    $pnl = app(TerritoryPnlService::class)->calculatePnlRollup(
        $region, now()->startOfMonth(), now()->endOfMonth()
    );

    $elapsed = microtime(true) - $start;
    expect($elapsed)->toBeLessThan(3.0, "P&L rollup completed in {$elapsed}s, expected < 3s");
    expect($pnl)->toHaveKey('revenue');
});

it('territory map loads with 100+ polygons without error', function () {
    $user = User::factory()->internal()->withRole('owner')->create();

    $boundary = ['type' => 'Polygon', 'coordinates' => [[[-90, 35], [-89, 35], [-89, 36], [-90, 36], [-90, 35]]]];
    SalesTerritory::factory()->count(100)->create(['boundary' => $boundary]);

    Livewire::actingAs($user)
        ->test('sales.territory-manager')
        ->assertSuccessful();
});
