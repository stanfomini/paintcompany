<?php

use App\Models\CompanySetting;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;

beforeEach(function () {
    CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
});

it('includes surface in all tiers when package_tiers is null', function () {
    $surface = EstimateAreaSurface::factory()->create(['package_tiers' => null]);

    expect($surface->isIncludedInTier('good'))->toBeTrue();
    expect($surface->isIncludedInTier('better'))->toBeTrue();
    expect($surface->isIncludedInTier('best'))->toBeTrue();
});

it('includes surface only in specified tiers', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'package_tiers' => ['better', 'best'],
    ]);

    expect($surface->isIncludedInTier('good'))->toBeFalse();
    expect($surface->isIncludedInTier('better'))->toBeTrue();
    expect($surface->isIncludedInTier('best'))->toBeTrue();
});

it('includes surface in single tier only', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'package_tiers' => ['best'],
    ]);

    expect($surface->isIncludedInTier('good'))->toBeFalse();
    expect($surface->isIncludedInTier('better'))->toBeFalse();
    expect($surface->isIncludedInTier('best'))->toBeTrue();
});

it('preserves pricing data when toggling tier inclusion', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'package_tiers' => ['best'],
        'tier_overrides' => [
            'best' => ['labor_hours' => 5.0, 'labor_cost' => 150.0, 'material_cost' => 50.0],
        ],
    ]);

    expect($surface->getLaborHoursForTier('best'))->toBe(5.0);
    expect($surface->isIncludedInTier('good'))->toBeFalse();
});

it('excludes surface from area COGS when not in tier', function () {
    $area = EstimateArea::factory()->create();

    // Walls: included in all tiers
    EstimateAreaSurface::factory()->forArea($area)->wall()->create([
        'labor_cost' => 100,
        'material_cost' => 50,
        'total' => 150,
        'is_included' => true,
        'package_tiers' => null,
    ]);

    // Crown molding: best only
    EstimateAreaSurface::factory()->forArea($area)->trim()->create([
        'labor_cost' => 40,
        'material_cost' => 20,
        'total' => 60,
        'is_included' => true,
        'package_tiers' => ['best'],
    ]);

    // Good tier: only walls
    $goodCogs = $area->getFullCOGS('good', bypassCache: true);
    // Best tier: walls + crown molding
    $bestCogs = $area->getFullCOGS('best', bypassCache: true);

    expect($bestCogs)->toBeGreaterThan($goodCogs);
});
