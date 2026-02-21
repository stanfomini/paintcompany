<?php

use App\Models\SalesTerritory;
use App\Models\User;

it('creates a region territory', function () {
    $region = SalesTerritory::factory()->region()->create(['name' => 'East Memphis']);

    expect($region->level)->toBe('region');
    expect($region->parent_id)->toBeNull();
});

it('creates a zone under a region', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();

    expect($zone->level)->toBe('zone');
    expect($zone->parent_id)->toBe($region->id);
    expect($zone->parent->id)->toBe($region->id);
    expect($region->children)->toHaveCount(1);
});

it('creates a block under a zone', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    expect($block->level)->toBe('block');
    expect($block->parent_id)->toBe($zone->id);
    expect($zone->children)->toHaveCount(1);
});

it('gets all descendant IDs from a region', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone1 = SalesTerritory::factory()->zone()->childOf($region)->create();
    $zone2 = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block1 = SalesTerritory::factory()->block()->childOf($zone1)->create();
    $block2 = SalesTerritory::factory()->block()->childOf($zone1)->create();

    $region->load('children.children');
    $ids = $region->getDescendantIds();

    expect($ids)->toContain($zone1->id, $zone2->id, $block1->id, $block2->id);
    expect($ids)->toHaveCount(4);
});

it('gets ancestor chain from a block', function () {
    $region = SalesTerritory::factory()->region()->create();
    $zone = SalesTerritory::factory()->zone()->childOf($region)->create();
    $block = SalesTerritory::factory()->block()->childOf($zone)->create();

    $ancestors = $block->getAncestors();

    expect($ancestors)->toHaveCount(2);
    expect($ancestors->first()->id)->toBe($zone->id);
    expect($ancestors->last()->id)->toBe($region->id);
});

it('assigns a manager to a territory', function () {
    $manager = User::factory()->internal()->create();
    $region = SalesTerritory::factory()->region()->managedBy($manager)->create();

    expect($region->manager->id)->toBe($manager->id);
});

it('scopes by level', function () {
    SalesTerritory::factory()->region()->create();
    SalesTerritory::factory()->zone()->count(2)->create();
    SalesTerritory::factory()->block()->count(3)->create();

    expect(SalesTerritory::regions()->count())->toBe(1);
    expect(SalesTerritory::zones()->count())->toBe(2);
    expect(SalesTerritory::blocks()->count())->toBe(3);
});
