<?php

use App\Models\CompanySetting;
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
