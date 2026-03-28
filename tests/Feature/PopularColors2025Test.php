<?php

use App\Models\PaintBrand;
use App\Models\PaintColor;

it('returns different colors for interior vs exterior popular queries', function () {
    $brand = PaintBrand::factory()->create();

    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Interior Only',
        'code' => 'TEST-INT',
        'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Exterior Only',
        'code' => 'TEST-EXT',
        'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Both Uses',
        'code' => 'TEST-BOTH',
        'intended_use' => 'both',
    ]);

    PaintColor::clearCache();

    $interior = PaintColor::getCachedPopularColors($brand->id, 'interior');
    $exterior = PaintColor::getCachedPopularColors($brand->id, 'exterior');

    // Interior should contain 'interior' and 'both', but not 'exterior'
    expect($interior->pluck('name')->toArray())
        ->toContain('Interior Only')
        ->toContain('Both Uses')
        ->not->toContain('Exterior Only');

    // Exterior should contain 'exterior' and 'both', but not 'interior'
    expect($exterior->pluck('name')->toArray())
        ->toContain('Exterior Only')
        ->toContain('Both Uses')
        ->not->toContain('Interior Only');
});

it('limits popular colors to 10 per query', function () {
    $brand = PaintBrand::factory()->create();

    for ($i = 0; $i < 15; $i++) {
        PaintColor::factory()->forBrand($brand)->popular()->create([
            'code' => "LIM-{$i}",
            'intended_use' => 'both',
        ]);
    }

    PaintColor::clearCache();

    $colors = PaintColor::getCachedPopularColors($brand->id, 'interior');
    expect($colors)->toHaveCount(10);
});

it('filters popular colors by brand', function () {
    $brandA = PaintBrand::factory()->create(['code' => 'AAA']);
    $brandB = PaintBrand::factory()->create(['code' => 'BBB']);

    PaintColor::factory()->forBrand($brandA)->popular()->create([
        'name' => 'Brand A Color',
        'code' => 'A-001',
        'intended_use' => 'both',
    ]);
    PaintColor::factory()->forBrand($brandB)->popular()->create([
        'name' => 'Brand B Color',
        'code' => 'B-001',
        'intended_use' => 'both',
    ]);

    PaintColor::clearCache();

    $colorsA = PaintColor::getCachedPopularColors($brandA->id, 'interior');
    $colorsB = PaintColor::getCachedPopularColors($brandB->id, 'interior');

    expect($colorsA->pluck('name')->toArray())
        ->toContain('Brand A Color')
        ->not->toContain('Brand B Color');

    expect($colorsB->pluck('name')->toArray())
        ->toContain('Brand B Color')
        ->not->toContain('Brand A Color');
});

it('excludes inactive colors from popular results', function () {
    $brand = PaintBrand::factory()->create();

    PaintColor::factory()->forBrand($brand)->popular()->create([
        'name' => 'Active Color',
        'code' => 'ACT-1',
        'intended_use' => 'both',
        'is_active' => true,
    ]);
    PaintColor::factory()->forBrand($brand)->create([
        'name' => 'Inactive Popular',
        'code' => 'INACT-1',
        'intended_use' => 'both',
        'is_popular' => true,
        'is_active' => false,
    ]);

    PaintColor::clearCache();

    $colors = PaintColor::getCachedPopularColors($brand->id, 'interior');
    expect($colors->pluck('name')->toArray())
        ->toContain('Active Color')
        ->not->toContain('Inactive Popular');
});

it('scopes for intended use includes both', function () {
    $brand = PaintBrand::factory()->create();

    PaintColor::factory()->forBrand($brand)->create([
        'code' => 'INT-1',
        'intended_use' => 'interior',
    ]);
    PaintColor::factory()->forBrand($brand)->create([
        'code' => 'EXT-1',
        'intended_use' => 'exterior',
    ]);
    PaintColor::factory()->forBrand($brand)->create([
        'code' => 'BOTH-1',
        'intended_use' => 'both',
    ]);

    $interior = PaintColor::forIntendedUse('interior')->pluck('code')->toArray();
    expect($interior)->toContain('INT-1')
        ->toContain('BOTH-1')
        ->not->toContain('EXT-1');

    $exterior = PaintColor::forIntendedUse('exterior')->pluck('code')->toArray();
    expect($exterior)->toContain('EXT-1')
        ->toContain('BOTH-1')
        ->not->toContain('INT-1');
});
