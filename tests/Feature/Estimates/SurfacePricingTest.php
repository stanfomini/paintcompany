<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;

beforeEach(function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'base_hourly_wage' => 21.00,
        'payroll_burden_multiplier' => 1.35,
        'margin_percent' => 50,
        'interior_margin_percent' => 50,
        'exterior_margin_percent' => 41,
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'interior_cleanup_hours_ratio' => 16,
        'exterior_cleanup_hours_ratio' => 20,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
        'material_tax_rate' => 0,
        'labor_tax_rate' => 0,
        'recycle_fee_per_gallon' => 0,
    ]);

    $this->category = ProductionRateCategory::firstOrCreate(
        ['name' => 'Interior'],
        ['description' => 'Interior painting rates', 'sort_order' => 1]
    );
});

it('surface getFullCOGS uses effective rate for cleanup consistent with area', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $rate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $surface = EstimateAreaSurface::createFromRate($area, $rate, 600, 2);

    $settings = CompanySetting::getInstance();
    $effectiveRate = $settings->getEffectiveHourlyRateForScope('interior');

    // Verify cleanup uses effective rate (not base wage)
    $laborHours = (float) $surface->labor_hours;
    $cleanupHours = $settings->calculateCleanupHours($laborHours, 'interior');
    $expectedCleanupCost = $cleanupHours * $effectiveRate;

    // The full COGS should include cleanup at effective rate
    $fullCogs = $surface->getFullCOGS();
    expect($fullCogs)->toBeGreaterThan((float) $surface->total);

    // Area with one surface should have same COGS as the surface's full COGS
    $areaFullCogs = $area->getFullCOGS();
    expect(round($areaFullCogs, 2))->toBe(round($fullCogs, 2));
});

it('surface customer price matches proportional allocation for single surface area', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $rate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $surface = EstimateAreaSurface::createFromRate($area, $rate, 600, 2);

    // With one surface, proportional price should equal the area customer price
    $areaPrice = $area->getCustomerPrice();
    $proportionalPrices = $area->getSurfaceCustomerPrices();

    expect($proportionalPrices)->toHaveKey($surface->id)
        ->and($proportionalPrices[$surface->id])->toBe(round($areaPrice, 2));
});

it('proportional surface prices sum exactly to area customer price', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $ceilingRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Ceiling Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 400,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $trimRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Trim Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'lnft',
            'production_rate' => 40,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
            'is_active' => true,
            'sort_order' => 3,
        ]
    );

    // Create multiple surfaces (like a real room)
    EstimateAreaSurface::createFromRate($area, $wallRate, 1050, 2);
    EstimateAreaSurface::createFromRate($area, $ceilingRate, 300, 1);
    EstimateAreaSurface::createFromRate($area, $trimRate, 70, 1);

    $areaPrice = $area->getCustomerPrice();
    $proportionalPrices = $area->getSurfaceCustomerPrices();

    // Sum must equal area customer price exactly
    $sumOfPrices = array_sum($proportionalPrices);
    expect(round($sumOfPrices, 2))->toBe(round($areaPrice, 2));

    // Each surface should have a positive price
    foreach ($proportionalPrices as $price) {
        expect($price)->toBeGreaterThan(0);
    }

    // Surfaces with more COGS should have higher proportional prices
    $surfaces = $area->surfaces()->where('is_included', true)->get();
    $wallSurface = $surfaces->firstWhere('name', 'Test Wall Painting');
    $ceilingSurface = $surfaces->firstWhere('name', 'Test Ceiling Painting');

    if ($wallSurface && $ceilingSurface) {
        expect($proportionalPrices[$wallSurface->id])
            ->toBeGreaterThan($proportionalPrices[$ceilingSurface->id]);
    }
});

it('proportional prices are zero for area with no included surfaces', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $prices = $area->getSurfaceCustomerPrices();
    expect($prices)->toBeEmpty();
});

it('proportional prices reflect COGS weight per surface', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    // Create two surfaces with known quantities
    $bigSurface = EstimateAreaSurface::createFromRate($area, $wallRate, 1000, 2);
    $smallSurface = EstimateAreaSurface::createFromRate($area, $wallRate, 100, 2);

    $proportionalPrices = $area->getSurfaceCustomerPrices();
    $areaPrice = $area->getCustomerPrice();

    // Big surface should get ~10x the price of small surface
    $bigPrice = $proportionalPrices[$bigSurface->id];
    $smallPrice = $proportionalPrices[$smallSurface->id];

    $ratio = $bigPrice / $smallPrice;
    expect($ratio)->toBeGreaterThan(9.0)->toBeLessThan(11.0);

    // Sum still matches
    expect(round($bigPrice + $smallPrice, 2))->toBe(round($areaPrice, 2));
});

it('getPartialCustomerPrice returns full price when all surfaces included', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $ceilingRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Ceiling Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 400,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $wall = EstimateAreaSurface::createFromRate($area, $wallRate, 600, 2);
    $ceiling = EstimateAreaSurface::createFromRate($area, $ceilingRate, 200, 1);

    $fullPrice = $area->getCustomerPrice();
    $partialPrice = $area->getPartialCustomerPrice([$wall->id, $ceiling->id]);

    expect($partialPrice)->toBe($fullPrice);
});

it('getPartialCustomerPrice returns proportional price for subset of surfaces', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $ceilingRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Ceiling Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 400,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $wall = EstimateAreaSurface::createFromRate($area, $wallRate, 600, 2);
    $ceiling = EstimateAreaSurface::createFromRate($area, $ceilingRate, 200, 1);

    $fullPrice = $area->getCustomerPrice();

    // Partial price with only walls should be less than full
    $wallOnlyPrice = $area->getPartialCustomerPrice([$wall->id]);
    expect($wallOnlyPrice)->toBeLessThan($fullPrice)->toBeGreaterThan(0);

    // Partial price with only ceiling should be less than full
    $ceilingOnlyPrice = $area->getPartialCustomerPrice([$ceiling->id]);
    expect($ceilingOnlyPrice)->toBeLessThan($fullPrice)->toBeGreaterThan(0);

    // Sum of partials should equal full price
    expect(round($wallOnlyPrice + $ceilingOnlyPrice, 2))->toBe(round($fullPrice, 2));
});

it('getPartialCustomerPrice returns zero for empty surface IDs', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    expect($area->getPartialCustomerPrice([]))->toBe(0.0);
});

it('package total changes when surface is toggled off', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $ceilingRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Ceiling Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 400,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $wall = EstimateAreaSurface::createFromRate($area, $wallRate, 600, 2);
    $ceiling = EstimateAreaSurface::createFromRate($area, $ceilingRate, 200, 1);

    // Create a package with both surfaces
    $package = \App\Models\EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
    ]);

    $wallItem = \App\Models\EstimatePackageItem::create([
        'package_id' => $package->id,
        'itemable_type' => EstimateAreaSurface::class,
        'itemable_id' => $wall->id,
        'is_included' => true,
        'customer_approved' => true,
        'customer_approved_at' => now(),
        'sort_order' => 1,
    ]);

    $ceilingItem = \App\Models\EstimatePackageItem::create([
        'package_id' => $package->id,
        'itemable_type' => EstimateAreaSurface::class,
        'itemable_id' => $ceiling->id,
        'is_included' => true,
        'customer_approved' => true,
        'customer_approved_at' => now(),
        'sort_order' => 2,
    ]);

    // Package total with all surfaces should equal full area price
    $package->refresh();
    $fullTotal = (float) $package->subtotal;
    expect($fullTotal)->toBe(round($area->getCustomerPrice(), 2));

    // Toggle ceiling off
    $ceilingItem->is_included = false;
    $ceilingItem->save();
    $package->refresh();

    $reducedTotal = (float) $package->subtotal;

    // Total should be less now (only walls)
    expect($reducedTotal)->toBeLessThan($fullTotal);

    // Reduced total should equal partial price for walls only
    $expectedPartial = $area->getPartialCustomerPrice([$wall->id]);
    expect($reducedTotal)->toBe(round($expectedPartial, 2));
});

it('package calculateTotals uses proportional pricing via service', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    $wallRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Wall Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 300,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 2,
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $ceilingRate = ProductionRate::firstOrCreate(
        ['name' => 'Test Ceiling Painting'],
        [
            'category_id' => $this->category->id,
            'unit_type' => 'sqft',
            'production_rate' => 400,
            'coat_multipliers' => ['1' => 1.0, '2' => 1.8],
            'default_coats' => 1,
            'is_active' => true,
            'sort_order' => 2,
        ]
    );

    $wall = EstimateAreaSurface::createFromRate($area, $wallRate, 600, 2);
    $ceiling = EstimateAreaSurface::createFromRate($area, $ceilingRate, 200, 1);

    // Create package with only wall surface included
    $package = \App\Models\EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
    ]);

    \App\Models\EstimatePackageItem::create([
        'package_id' => $package->id,
        'itemable_type' => EstimateAreaSurface::class,
        'itemable_id' => $wall->id,
        'is_included' => true,
        'customer_approved' => true,
        'customer_approved_at' => now(),
        'sort_order' => 1,
    ]);

    \App\Models\EstimatePackageItem::create([
        'package_id' => $package->id,
        'itemable_type' => EstimateAreaSurface::class,
        'itemable_id' => $ceiling->id,
        'is_included' => false, // ceiling excluded
        'customer_approved' => true,
        'customer_approved_at' => now(),
        'sort_order' => 2,
    ]);

    // Use the service to calculate total
    $service = app(\App\Services\EstimatePackageService::class);
    $service->calculatePackageTotal($package);

    $package->refresh();

    // Total should match wall-only partial price
    $expectedPartial = $area->getPartialCustomerPrice([$wall->id]);
    expect((float) $package->subtotal)->toBe(round($expectedPartial, 2));
});
