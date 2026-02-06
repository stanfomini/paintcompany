<?php

use App\Models\EstimateArea;
use App\Models\EstimateAreaAddon;
use App\Models\NestedAreaTemplate;

it('auto-calculates total_price on save', function () {
    $area = EstimateArea::factory()->create();
    $template = NestedAreaTemplate::factory()->create(['fixed_price' => 150.00]);

    $addon = EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template->id,
        'quantity' => 3,
        'unit_price' => 150.00,
    ]);

    expect($addon->total_price)->toBe('450.00');
});

it('updates total_price when quantity changes', function () {
    $area = EstimateArea::factory()->create();
    $template = NestedAreaTemplate::factory()->create(['fixed_price' => 100.00]);

    $addon = EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template->id,
        'quantity' => 2,
        'unit_price' => 100.00,
    ]);

    expect($addon->total_price)->toBe('200.00');

    $addon->update(['quantity' => 5]);

    expect($addon->fresh()->total_price)->toBe('500.00');
});

it('belongs to estimate area', function () {
    $area = EstimateArea::factory()->create();
    $template = NestedAreaTemplate::factory()->create();

    $addon = EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template->id,
        'quantity' => 1,
        'unit_price' => 75.00,
    ]);

    expect($addon->estimateArea)->toBeInstanceOf(EstimateArea::class);
    expect($addon->estimateArea->id)->toBe($area->id);
});

it('belongs to nested area template', function () {
    $area = EstimateArea::factory()->create();
    $template = NestedAreaTemplate::factory()->create(['name' => 'Walk-in Closet']);

    $addon = EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template->id,
        'quantity' => 1,
        'unit_price' => 125.00,
    ]);

    expect($addon->nestedAreaTemplate)->toBeInstanceOf(NestedAreaTemplate::class);
    expect($addon->nestedAreaTemplate->name)->toBe('Walk-in Closet');
});

it('can be accessed from estimate area via addons relationship', function () {
    $area = EstimateArea::factory()->create();
    $template1 = NestedAreaTemplate::factory()->create(['name' => 'Small Closet']);
    $template2 = NestedAreaTemplate::factory()->create(['name' => 'Half Bath']);

    EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template1->id,
        'quantity' => 2,
        'unit_price' => 75.00,
    ]);

    EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template2->id,
        'quantity' => 1,
        'unit_price' => 175.00,
    ]);

    expect($area->addons)->toHaveCount(2);
    expect((float) $area->addons->sum('total_price'))->toBe(325.00);
});

it('cascades delete when estimate area is deleted', function () {
    $area = EstimateArea::factory()->create();
    $template = NestedAreaTemplate::factory()->create();

    $addon = EstimateAreaAddon::create([
        'estimate_area_id' => $area->id,
        'nested_area_template_id' => $template->id,
        'quantity' => 1,
        'unit_price' => 100.00,
    ]);

    $addonId = $addon->id;
    $area->delete();

    expect(EstimateAreaAddon::find($addonId))->toBeNull();
});
