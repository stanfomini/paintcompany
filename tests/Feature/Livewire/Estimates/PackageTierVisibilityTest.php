<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\PackageTemplate;
use App\Models\User;
use App\Services\EstimatePackageGeneratorService;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->deal = Deal::factory()->create();

    // Set up consistent company settings
    $settings = CompanySetting::getInstance();
    $settings->update([
        'base_hourly_wage' => 21.00,
        'payroll_burden_multiplier' => 1.35,
        'margin_percent' => 50,
        'interior_hourly_wage' => 21.00,
        'interior_margin_percent' => 50,
        'interior_cleanup_hours_ratio' => 16,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_hourly_wage' => 27.00,
        'exterior_margin_percent' => 41,
        'exterior_cleanup_hours_ratio' => 20,
        'exterior_sundries_cost_per_hour' => 1.00,
        'material_tax_rate' => 7.5,
        'recycle_fee_per_gallon' => 0.54,
    ]);

    $this->estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'created_by' => $this->user->id,
        'status' => 'draft',
        'tax_rate' => 0,
    ]);
});

// =========================================================================
// MODEL HELPER TESTS
// =========================================================================

it('returns true for all tiers when package_tiers is null', function () {
    $area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'package_tiers' => null,
    ]);

    expect($area->isIncludedInTier('good'))->toBeTrue()
        ->and($area->isIncludedInTier('better'))->toBeTrue()
        ->and($area->isIncludedInTier('best'))->toBeTrue();
});

it('filters areas by package_tiers', function () {
    $area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'package_tiers' => ['best'],
    ]);

    expect($area->isIncludedInTier('good'))->toBeFalse()
        ->and($area->isIncludedInTier('better'))->toBeFalse()
        ->and($area->isIncludedInTier('best'))->toBeTrue();
});

it('returns empty array from getSettingsForTier when no overrides', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => EstimateArea::factory()->create(['estimate_id' => $this->estimate->id])->id,
        'tier_overrides' => null,
    ]);

    expect($surface->getSettingsForTier('good'))->toBe([])
        ->and($surface->getSettingsForTier(null))->toBe([])
        ->and($surface->hasTierOverrides())->toBeFalse();
});

it('returns tier settings from getSettingsForTier', function () {
    $overrides = [
        'good' => ['default_coats' => 1, 'labor_hours' => 1.0],
        'better' => ['default_coats' => 2, 'labor_hours' => 1.8],
    ];

    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => EstimateArea::factory()->create(['estimate_id' => $this->estimate->id])->id,
        'tier_overrides' => $overrides,
    ]);

    expect($surface->hasTierOverrides())->toBeTrue()
        ->and($surface->getSettingsForTier('good')['default_coats'])->toBe(1)
        ->and($surface->getSettingsForTier('better')['default_coats'])->toBe(2)
        ->and($surface->getSettingsForTier('best'))->toBe([]);
});

it('gets labor hours for tier from tier_overrides', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => EstimateArea::factory()->create(['estimate_id' => $this->estimate->id])->id,
        'labor_hours' => 2.00,
        'tier_overrides' => [
            'good' => ['labor_hours' => 1.00],
            'better' => ['labor_hours' => 1.80],
            'best' => ['labor_hours' => 2.50],
        ],
    ]);

    expect($surface->getLaborHoursForTier(null))->toBe(2.00)
        ->and($surface->getLaborHoursForTier('good'))->toBe(1.00)
        ->and($surface->getLaborHoursForTier('better'))->toBe(1.80)
        ->and($surface->getLaborHoursForTier('best'))->toBe(2.50);
});

it('falls back to base labor_hours when tier has no override', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => EstimateArea::factory()->create(['estimate_id' => $this->estimate->id])->id,
        'labor_hours' => 2.00,
        'tier_overrides' => [
            'good' => ['labor_hours' => 1.00],
        ],
    ]);

    expect($surface->getLaborHoursForTier('better'))->toBe(2.00)
        ->and($surface->getLaborHoursForTier('best'))->toBe(2.00);
});

// =========================================================================
// BACKWARD COMPATIBILITY
// =========================================================================

it('preserves existing behavior when tier_overrides and package_tiers are null', function () {
    $area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'package_tiers' => null,
    ]);

    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => $area->id,
        'labor_hours' => 2.00,
        'labor_cost' => 50.00,
        'material_cost' => 30.00,
        'total' => 80.00,
        'tier_overrides' => null,
    ]);

    // All tiers should use the same values
    expect($surface->getLaborHoursForTier('good'))->toBe(2.00)
        ->and($surface->getLaborHoursForTier('better'))->toBe(2.00)
        ->and($surface->getLaborHoursForTier('best'))->toBe(2.00)
        ->and($area->isIncludedInTier('good'))->toBeTrue()
        ->and($area->isIncludedInTier('better'))->toBeTrue()
        ->and($area->isIncludedInTier('best'))->toBeTrue();
});

// =========================================================================
// SAVE/LOAD ROUNDTRIP
// =========================================================================

it('persists and restores tier_overrides on surfaces', function () {
    $overrides = [
        '_base' => ['default_coats' => 2, 'labor_hours' => 2.0],
        'good' => ['default_coats' => 1, 'labor_hours' => 1.0, 'labor_cost' => 25.0],
        'better' => ['default_coats' => 2, 'labor_hours' => 1.8, 'labor_cost' => 45.0],
        'best' => ['default_coats' => 2, 'labor_hours' => 2.5, 'labor_cost' => 62.5],
    ];

    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => EstimateArea::factory()->create(['estimate_id' => $this->estimate->id])->id,
        'tier_overrides' => $overrides,
    ]);

    $loaded = EstimateAreaSurface::find($surface->id);

    expect($loaded->hasTierOverrides())->toBeTrue()
        ->and($loaded->getSettingsForTier('good')['default_coats'])->toBe(1)
        ->and($loaded->getSettingsForTier('best')['labor_hours'])->toBe(2.5)
        ->and($loaded->getSettingsForTier('_base')['default_coats'])->toBe(2);
});

it('persists and restores package_tiers on areas', function () {
    $area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'package_tiers' => ['better', 'best'],
    ]);

    $loaded = EstimateArea::find($area->id);

    expect($loaded->package_tiers)->toBe(['better', 'best'])
        ->and($loaded->isIncludedInTier('good'))->toBeFalse()
        ->and($loaded->isIncludedInTier('better'))->toBeTrue()
        ->and($loaded->isIncludedInTier('best'))->toBeTrue();
});

// =========================================================================
// PACKAGE GENERATION WITH AREA VISIBILITY
// =========================================================================

it('excludes areas from packages when package_tiers limits visibility', function () {
    // Create package templates (Good/Better/Best)
    $goodTemplate = PackageTemplate::factory()->create([
        'tier' => 'good',
        'name' => 'Good Package',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $bestTemplate = PackageTemplate::factory()->create([
        'tier' => 'best',
        'name' => 'Best Package',
        'is_active' => true,
        'sort_order' => 2,
    ]);

    // Area visible in all tiers
    $allTierArea = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Living Room',
        'package_tiers' => null,
    ]);
    EstimateAreaSurface::factory()->create([
        'area_id' => $allTierArea->id,
        'is_included' => true,
    ]);

    // Area visible only in Best
    $bestOnlyArea = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Garage Door',
        'package_tiers' => ['best'],
    ]);
    EstimateAreaSurface::factory()->create([
        'area_id' => $bestOnlyArea->id,
        'is_included' => true,
    ]);

    $service = app(EstimatePackageGeneratorService::class);
    $packages = $service->generateFromTemplates($this->estimate);

    // Good package: Garage Door surface should be excluded
    $goodPackage = $packages->firstWhere('tier', EstimatePackage::TIER_BASIC);
    if ($goodPackage) {
        $garageItems = $goodPackage->items()
            ->where('itemable_type', EstimateAreaSurface::class)
            ->whereIn('itemable_id', $bestOnlyArea->surfaces->pluck('id'))
            ->get();

        foreach ($garageItems as $item) {
            expect($item->is_included)->toBeFalse();
        }
    }

    // Best package: all surfaces should be included
    $bestPackage = $packages->firstWhere('tier', EstimatePackage::TIER_PREMIUM);
    if ($bestPackage) {
        $garageItems = $bestPackage->items()
            ->where('itemable_type', EstimateAreaSurface::class)
            ->whereIn('itemable_id', $bestOnlyArea->surfaces->pluck('id'))
            ->get();

        foreach ($garageItems as $item) {
            expect($item->is_included)->toBeTrue();
        }
    }
});

it('excludes fixed-price areas from packages based on package_tiers', function () {
    $goodTemplate = PackageTemplate::factory()->create([
        'tier' => 'good',
        'name' => 'Good',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $bestTemplate = PackageTemplate::factory()->create([
        'tier' => 'best',
        'name' => 'Best',
        'is_active' => true,
        'sort_order' => 2,
    ]);

    // Fixed-price area visible only in Best
    $fixedArea = EstimateArea::factory()->fixedPrice(200.00)->create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Closet',
        'package_tiers' => ['best'],
    ]);

    $service = app(EstimatePackageGeneratorService::class);
    $packages = $service->generateFromTemplates($this->estimate);

    // Good package: fixed area should be excluded
    $goodPackage = $packages->firstWhere('tier', EstimatePackage::TIER_BASIC);
    if ($goodPackage) {
        $closetItem = $goodPackage->items()
            ->where('itemable_type', EstimateArea::class)
            ->where('itemable_id', $fixedArea->id)
            ->first();

        if ($closetItem) {
            expect($closetItem->is_included)->toBeFalse();
        }
    }

    // Best package: fixed area should be included
    $bestPackage = $packages->firstWhere('tier', EstimatePackage::TIER_PREMIUM);
    if ($bestPackage) {
        $closetItem = $bestPackage->items()
            ->where('itemable_type', EstimateArea::class)
            ->where('itemable_id', $fixedArea->id)
            ->first();

        if ($closetItem) {
            expect($closetItem->is_included)->toBeTrue();
        }
    }
});

// =========================================================================
// TIER-SPECIFIC LABOR IN AREA COGS
// =========================================================================

it('uses tier-specific labor hours in area getFullCOGS', function () {
    $area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'scope_type' => 'interior',
        'cached_cogs' => null,
        'cached_customer_price' => null,
        'cached_tier_prices' => null,
    ]);

    $hourlyRate = 21.00 * 1.35; // effective rate

    EstimateAreaSurface::factory()->create([
        'area_id' => $area->id,
        'labor_hours' => 2.00,
        'labor_cost' => 2.00 * $hourlyRate,
        'material_cost' => 30.00,
        'total' => (2.00 * $hourlyRate) + 30.00,
        'is_included' => true,
        'tier_overrides' => [
            'good' => ['labor_hours' => 1.00, 'labor_cost' => 1.00 * $hourlyRate],
            'best' => ['labor_hours' => 3.00, 'labor_cost' => 3.00 * $hourlyRate],
        ],
    ]);

    $baseCogs = $area->getFullCOGS(null);
    $goodCogs = $area->getFullCOGS('good');
    $bestCogs = $area->getFullCOGS('best');

    // Good should have lower COGS (fewer hours)
    expect($goodCogs)->toBeLessThan($baseCogs);
    // Best should have higher COGS (more hours)
    expect($bestCogs)->toBeGreaterThan($baseCogs);
});

// =========================================================================
// RESTORE PACKAGE SELECTIONS RESPECTS AREA TIER VISIBILITY
// =========================================================================

it('does not restore is_included for items excluded by area package_tiers', function () {
    $goodTemplate = PackageTemplate::factory()->create([
        'tier' => 'good',
        'name' => 'Good',
        'is_active' => true,
        'sort_order' => 0,
    ]);
    $bestTemplate = PackageTemplate::factory()->create([
        'tier' => 'best',
        'name' => 'Best',
        'is_active' => true,
        'sort_order' => 2,
    ]);

    // Fixed-price area visible only in Good+Better (NOT Best)
    $garageDoor = EstimateArea::factory()->fixedPrice(200.00)->create([
        'estimate_id' => $this->estimate->id,
        'name' => 'Garage Door',
        'package_tiers' => ['good', 'better'],
    ]);

    $service = app(EstimatePackageGeneratorService::class);

    // First generation: correctly excludes Garage Door from Best
    $packages = $service->generateFromTemplates($this->estimate);

    $bestPackage = $packages->firstWhere('tier', \App\Models\EstimatePackage::TIER_PREMIUM);
    if ($bestPackage) {
        $garageItem = $bestPackage->items()
            ->where('itemable_type', EstimateArea::class)
            ->where('itemable_id', $garageDoor->id)
            ->first();
        expect($garageItem)->not->toBeNull();
        expect($garageItem->is_included)->toBeFalse();
    }

    // Snapshot the selections (simulating what happens on edit/save)
    $snapshot = $service->snapshotPackageSelections($this->estimate);

    // Now simulate: user manually included Garage Door in Best package before
    // (this is what happens when old packages had it included in all tiers)
    $bestKey = \App\Models\EstimatePackage::TIER_PREMIUM.':area:Garage Door';
    $snapshot[$bestKey] = ['is_included' => true];

    // Clear and regenerate
    $service->clearGeneratedPackages($this->estimate);
    $packages = $service->generateFromTemplates($this->estimate);

    // Restore should NOT override is_included for Garage Door in Best
    // because area package_tiers excludes it from Best
    $service->restorePackageSelections($this->estimate, $snapshot);

    $bestPackage = $this->estimate->packages()
        ->where('tier', \App\Models\EstimatePackage::TIER_PREMIUM)
        ->first();
    if ($bestPackage) {
        $garageItem = $bestPackage->items()
            ->where('itemable_type', EstimateArea::class)
            ->where('itemable_id', $garageDoor->id)
            ->first();
        expect($garageItem)->not->toBeNull();
        // Must stay false — area tier visibility takes precedence over snapshot
        expect($garageItem->is_included)->toBeFalse();
    }
});

it('calculates areas subtotal for tier excluding non-visible areas', function () {
    $allArea = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'package_tiers' => null,
        'cached_cogs' => 100.0,
        'cached_customer_price' => 200.0,
        'cached_tier_prices' => [
            'good' => ['price' => 200.0, 'cogs' => 100.0],
            'better' => ['price' => 200.0, 'cogs' => 100.0],
            'best' => ['price' => 200.0, 'cogs' => 100.0],
        ],
    ]);

    $bestOnlyArea = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'package_tiers' => ['best'],
        'cached_cogs' => 80.0,
        'cached_customer_price' => 160.0,
        'cached_tier_prices' => [
            'good' => ['price' => 160.0, 'cogs' => 80.0],
            'better' => ['price' => 160.0, 'cogs' => 80.0],
            'best' => ['price' => 160.0, 'cogs' => 80.0],
        ],
    ]);

    $this->estimate->refresh();

    $goodTotal = $this->estimate->calculateAreasSubtotalForTier('good');
    $bestTotal = $this->estimate->calculateAreasSubtotalForTier('best');

    // Good should only include allArea (bestOnlyArea excluded)
    expect($goodTotal)->toBe(200.00);
    // Best should include both areas
    expect($bestTotal)->toBe(360.00);
});
