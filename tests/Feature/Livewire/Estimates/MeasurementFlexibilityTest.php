<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();
});

it('defaults measurement_type to dimensions', function () {
    $area = EstimateArea::factory()->create();
    expect($area->measurement_type)->toBe('dimensions');
});

it('defaults wall_count to 4', function () {
    $area = EstimateArea::factory()->create();
    expect($area->wall_count)->toBe(4);
});

it('calculates wall_sqft with wall_count=4 matching existing behavior', function () {
    $area = EstimateArea::factory()->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
        'wall_count' => 4,
    ]);

    $fullPerimeter = 2 * (14 + 12); // 52
    $expectedWallSqft = $fullPerimeter * 9; // 468

    expect((float) $area->wall_sqft)->toBe(round((float) $expectedWallSqft, 2));
});

it('calculates wall_sqft with wall_count=2 as half', function () {
    $area = EstimateArea::factory()->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
        'wall_count' => 2,
    ]);

    $fullPerimeter = 2 * (14 + 12); // 52
    $halfPerimeter = $fullPerimeter * (2 / 4); // 26
    $expectedWallSqft = $halfPerimeter * 9; // 234

    expect((float) $area->wall_sqft)->toBe(round($expectedWallSqft, 2));
});

it('calculates wall_sqft with wall_count=1 as quarter', function () {
    $area = EstimateArea::factory()->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
        'wall_count' => 1,
    ]);

    $fullPerimeter = 2 * (14 + 12); // 52
    $quarterPerimeter = $fullPerimeter * (1 / 4); // 13
    $expectedWallSqft = $quarterPerimeter * 9; // 117

    expect((float) $area->wall_sqft)->toBe(round($expectedWallSqft, 2));
});

it('calculates wall_sqft with wall_count=3 as 75%', function () {
    $area = EstimateArea::factory()->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
        'wall_count' => 3,
    ]);

    $fullPerimeter = 2 * (14 + 12); // 52
    $threeQuarterPerimeter = $fullPerimeter * (3 / 4); // 39
    $expectedWallSqft = $threeQuarterPerimeter * 9; // 351

    expect((float) $area->wall_sqft)->toBe(round($expectedWallSqft, 2));
});

it('uses direct_sqft as wall_sqft in sqft mode', function () {
    $area = EstimateArea::factory()->directSqft(350.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->wall_sqft)->toBe(350.0);
});

it('derives perimeter_lnft from sqft and height in sqft mode', function () {
    $area = EstimateArea::factory()->directSqft(360.0)->create([
        'height' => 9,
    ]);

    // perimeter = sqft / height = 360 / 9 = 40
    expect((float) $area->perimeter_lnft)->toBe(40.0);
});

it('uses direct_lnft as perimeter_lnft in lnft mode', function () {
    $area = EstimateArea::factory()->directLnft(45.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->perimeter_lnft)->toBe(45.0);
});

it('derives wall_sqft from lnft and height in lnft mode', function () {
    $area = EstimateArea::factory()->directLnft(45.0)->create([
        'height' => 9,
    ]);

    // wall_sqft = lnft * height = 45 * 9 = 405
    expect((float) $area->wall_sqft)->toBe(405.0);
});

it('uses direct_ceiling_sqft in sqft mode', function () {
    $area = EstimateArea::factory()->directSqft(350.0, 200.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->ceiling_sqft)->toBe(200.0);
    expect((float) $area->floor_sqft)->toBe(200.0);
});

it('uses direct_ceiling_sqft in lnft mode', function () {
    $area = EstimateArea::factory()->directLnft(45.0, 180.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->ceiling_sqft)->toBe(180.0);
    expect((float) $area->floor_sqft)->toBe(180.0);
});

it('sets ceiling_sqft to zero when direct_ceiling_sqft is null in sqft mode', function () {
    $area = EstimateArea::factory()->directSqft(350.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->ceiling_sqft)->toBe(0.0);
    expect((float) $area->floor_sqft)->toBe(0.0);
});

it('sets ceiling_sqft to zero when direct_ceiling_sqft is null in lnft mode', function () {
    $area = EstimateArea::factory()->directLnft(45.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->ceiling_sqft)->toBe(0.0);
    expect((float) $area->floor_sqft)->toBe(0.0);
});

it('sets baseboard_lnft equal to perimeter_lnft in sqft mode', function () {
    $area = EstimateArea::factory()->directSqft(360.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->baseboard_lnft)->toBe((float) $area->perimeter_lnft);
});

it('sets baseboard_lnft equal to perimeter_lnft in lnft mode', function () {
    $area = EstimateArea::factory()->directLnft(45.0)->create([
        'height' => 9,
    ]);

    expect((float) $area->baseboard_lnft)->toBe(45.0);
});

it('customer price chain works for sqft measurement type', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->directSqft(400.0)->create([
        'estimate_id' => $estimate->id,
        'height' => 9,
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'quantity' => 400,
        'production_rate_value' => 200,
        'labor_hours' => 2.0,
        'labor_cost' => 90.0,
        'material_cost' => 30.0,
        'total' => 120.0,
        'is_included' => true,
    ]);

    expect($area->getFullCOGS())->toBeGreaterThan(0);
    expect($area->getCustomerPrice())->toBeGreaterThan(0);
    expect($area->getCustomerPrice())->toBeGreaterThan($area->getFullCOGS());
});

it('customer price chain works for lnft measurement type', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->directLnft(50.0)->create([
        'estimate_id' => $estimate->id,
        'height' => 9,
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'quantity' => 50,
        'production_rate_value' => 100,
        'labor_hours' => 0.5,
        'labor_cost' => 22.50,
        'material_cost' => 10.0,
        'total' => 32.50,
        'is_included' => true,
    ]);

    expect($area->getFullCOGS())->toBeGreaterThan(0);
    expect($area->getCustomerPrice())->toBeGreaterThan(0);
});

it('uses factory directSqft state correctly', function () {
    $area = EstimateArea::factory()->directSqft(500.0, 150.0)->create([
        'height' => 10,
    ]);

    expect($area->measurement_type)->toBe('sqft');
    expect((float) $area->direct_sqft)->toBe(500.0);
    expect((float) $area->direct_ceiling_sqft)->toBe(150.0);
    expect((float) $area->length)->toBe(0.0);
    expect((float) $area->width)->toBe(0.0);
});

it('uses factory directLnft state correctly', function () {
    $area = EstimateArea::factory()->directLnft(60.0, 200.0)->create([
        'height' => 10,
    ]);

    expect($area->measurement_type)->toBe('lnft');
    expect((float) $area->direct_lnft)->toBe(60.0);
    expect((float) $area->direct_ceiling_sqft)->toBe(200.0);
    expect((float) $area->length)->toBe(0.0);
    expect((float) $area->width)->toBe(0.0);
});

it('uses factory wallCount state correctly', function () {
    $area = EstimateArea::factory()->wallCount(2)->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
    ]);

    expect($area->wall_count)->toBe(2);
});

it('clamps wall_count to range 1-4 via factory', function () {
    $area = EstimateArea::factory()->wallCount(0)->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
    ]);
    // Factory clamps to min 1
    expect($area->wall_count)->toBe(1);

    $area2 = EstimateArea::factory()->wallCount(10)->create([
        'length' => 14,
        'width' => 12,
        'height' => 9,
    ]);
    // Factory clamps to max 4
    expect($area2->wall_count)->toBe(4);
});
