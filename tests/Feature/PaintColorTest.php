<?php

use App\Models\EstimateAreaPaintSelection;
use App\Models\PaintBrand;
use App\Models\PaintColor;

it('can create a paint color', function () {
    $brand = PaintBrand::factory()->create();
    $color = PaintColor::factory()->forBrand($brand)->create([
        'name' => 'Iron Ore',
        'code' => 'SW-7069',
        'hex_value' => '#433F3E',
    ]);

    expect($color)->toBeInstanceOf(PaintColor::class)
        ->and($color->name)->toBe('Iron Ore')
        ->and($color->hex_value)->toBe('#433F3E')
        ->and($color->brand->id)->toBe($brand->id);
});

it('belongs to a brand', function () {
    $color = PaintColor::factory()->create();
    expect($color->brand)->toBeInstanceOf(PaintBrand::class);
});

it('brand has colors relationship', function () {
    $brand = PaintBrand::factory()->create();
    PaintColor::factory()->count(3)->forBrand($brand)->create();

    expect($brand->colors)->toHaveCount(3);
    expect($brand->activeColors)->toHaveCount(3);
});

it('can search by name', function () {
    $brand = PaintBrand::factory()->create();
    PaintColor::factory()->forBrand($brand)->create(['name' => 'Iron Ore', 'code' => 'SW-7069']);
    PaintColor::factory()->forBrand($brand)->create(['name' => 'White Dove', 'code' => 'OC-17']);

    $results = PaintColor::search('Iron')->get();
    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Iron Ore');
});

it('can search by code', function () {
    PaintColor::factory()->create(['code' => 'SW-7069', 'name' => 'Iron Ore']);

    $results = PaintColor::search('SW-7069')->get();
    expect($results)->toHaveCount(1);
});

it('scopes to active colors', function () {
    PaintColor::factory()->create(['is_active' => true]);
    PaintColor::factory()->create(['is_active' => false]);

    expect(PaintColor::active()->count())->toBe(1);
});

it('scopes to popular colors', function () {
    PaintColor::factory()->create(['is_popular' => true]);
    PaintColor::factory()->create(['is_popular' => false]);

    expect(PaintColor::popular()->count())->toBe(1);
});

it('generates full name with brand', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Sherwin-Williams']);
    $color = PaintColor::factory()->forBrand($brand)->create(['name' => 'Iron Ore']);

    expect($color->full_name)->toBe('Sherwin-Williams Iron Ore');
});

it('enforces unique brand+code constraint', function () {
    $brand = PaintBrand::factory()->create();
    PaintColor::factory()->forBrand($brand)->create(['code' => 'SW-7069']);

    PaintColor::factory()->forBrand($brand)->create(['code' => 'SW-7069']);
})->throws(\Illuminate\Database\QueryException::class);

it('generates display name with code', function () {
    $color = PaintColor::factory()->create(['name' => 'Iron Ore', 'code' => 'SW-7069']);

    expect($color->display_name)->toBe('Iron Ore (SW-7069)');
});

it('scopes by color family', function () {
    PaintColor::factory()->create(['color_family' => 'blue']);
    PaintColor::factory()->create(['color_family' => 'gray']);

    expect(PaintColor::inFamily('blue')->count())->toBe(1);
});

it('can link paint color to estimate area paint selection', function () {
    $color = PaintColor::factory()->create();
    $selection = EstimateAreaPaintSelection::factory()->create(['paint_color_id' => $color->id]);

    expect($selection->paintColor->id)->toBe($color->id);
});

it('factory withHex state sets RGB values correctly', function () {
    $color = PaintColor::factory()->withHex('#FF8040')->create();

    expect($color->hex_value)->toBe('#FF8040')
        ->and($color->rgb_r)->toBe(255)
        ->and($color->rgb_g)->toBe(128)
        ->and($color->rgb_b)->toBe(64);
});
