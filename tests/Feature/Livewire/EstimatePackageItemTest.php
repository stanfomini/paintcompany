<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;

it('can create an estimate package item with an estimate item', function () {
    $package = EstimatePackage::factory()->create();
    $item = EstimateItem::factory()->create(['total' => 500.00]);

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($item)
        ->create();

    expect($packageItem)
        ->package_id->toBe($package->id)
        ->itemable_type->toBe(EstimateItem::class)
        ->itemable_id->toBe($item->id)
        ->is_included->toBeTrue()
        ->customer_approved->toBeTrue();
});

it('can create an estimate package item with an estimate area surface', function () {
    $package = EstimatePackage::factory()->create();
    $surface = EstimateAreaSurface::factory()->create(['total' => 750.00]);

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateAreaSurface($surface)
        ->create();

    expect($packageItem)
        ->itemable_type->toBe(EstimateAreaSurface::class)
        ->itemable_id->toBe($surface->id);
});

it('can create an estimate package item with an estimate area', function () {
    $package = EstimatePackage::factory()->create();
    $area = EstimateArea::factory()->create();

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateArea($area)
        ->create();

    expect($packageItem)
        ->itemable_type->toBe(EstimateArea::class)
        ->itemable_id->toBe($area->id);
});

it('returns price override as effective price when set', function () {
    $item = EstimateItem::factory()->create(['total' => 500.00]);
    $package = EstimatePackage::factory()->create();

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($item)
        ->withPriceOverride(350.00)
        ->create();

    expect($packageItem->effective_price)->toBe(350.00);
});

it('returns itemable total as effective price when no override', function () {
    $item = EstimateItem::factory()->create([
        'quantity' => 10,
        'unit_price' => 50.00,
        'total' => 500.00,
    ]);
    $package = EstimatePackage::factory()->create();

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($item)
        ->create(['price_override' => null]);

    expect($packageItem->effective_price)->toBe(500.00);
});

it('returns surface total as effective price for area surface', function () {
    // Create surface with values that will produce a known total
    // Total is calculated as: labor_cost + material_cost
    // Labor cost = (quantity / production_rate_value) * coats * hourly_labor_rate
    // Material cost = quantity * material_cost_per_unit * coats
    $surface = EstimateAreaSurface::factory()->create([
        'quantity' => 100,
        'production_rate_value' => 100, // 1 hour of labor
        'hourly_labor_rate' => 50,
        'material_cost_per_unit' => 1,
        'coats' => 1,
        // total = (100/100)*1*50 + 100*1*1 = 50 + 100 = 150
    ]);
    $package = EstimatePackage::factory()->create();

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateAreaSurface($surface)
        ->create(['price_override' => null]);

    expect($packageItem->effective_price)->toBe(150.0);
});

it('returns zero as effective price when itemable is missing', function () {
    $package = EstimatePackage::factory()->create();

    $packageItem = EstimatePackageItem::factory()
        ->forPackage($package)
        ->create([
            'itemable_type' => EstimateItem::class,
            'itemable_id' => 99999,
            'price_override' => null,
        ]);

    expect($packageItem->effective_price)->toBe(0.0);
});

it('scopes included items correctly', function () {
    $package = EstimatePackage::factory()->create();

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->count(3)
        ->create(['is_included' => true]);

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->count(2)
        ->excluded()
        ->create();

    expect(EstimatePackageItem::included()->count())->toBe(3);
});

it('scopes approved items correctly', function () {
    $package = EstimatePackage::factory()->create();

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->count(4)
        ->approved()
        ->create();

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->count(2)
        ->notApproved()
        ->create();

    expect(EstimatePackageItem::approved()->count())->toBe(4);
});

it('triggers package calculate totals when saved', function () {
    $estimate = Estimate::factory()->create();
    $package = EstimatePackage::factory()
        ->forEstimate($estimate)
        ->create([
            'subtotal' => 0,
            'total' => 0,
            'tax_rate' => 0,
            'discount_amount' => 0,
            'discount_percent' => 0,
            'warranty_price' => 0,
        ]);

    $item = EstimateItem::factory()->create([
        'quantity' => 10,
        'unit_price' => 100.00,
        'total' => 1000.00,
    ]);

    EstimatePackageItem::factory()
        ->forPackage($package)
        ->forEstimateItem($item)
        ->create(['price_override' => null]);

    $package->refresh();

    expect((float) $package->subtotal)->toBe(1000.00);
    expect((float) $package->total)->toBe(1000.00);
});

it('casts quantity and price override as decimals', function () {
    $packageItem = EstimatePackageItem::factory()
        ->withQuantity(5.5)
        ->withPriceOverride(123.45)
        ->create();

    expect($packageItem->quantity)->toBe('5.50')
        ->and($packageItem->price_override)->toBe('123.45');
});

it('casts is_included and customer_approved as booleans', function () {
    $packageItem = EstimatePackageItem::factory()->create([
        'is_included' => 1,
        'customer_approved' => 0,
    ]);

    expect($packageItem->is_included)->toBeTrue()
        ->and($packageItem->customer_approved)->toBeFalse();
});

it('casts customer_approved_at as datetime', function () {
    $packageItem = EstimatePackageItem::factory()
        ->approved()
        ->create();

    expect($packageItem->customer_approved_at)->toBeInstanceOf(Carbon\CarbonInterface::class);
});

it('has proper fillable attributes', function () {
    $fillable = (new EstimatePackageItem)->getFillable();

    expect($fillable)->toContain('package_id')
        ->toContain('itemable_type')
        ->toContain('itemable_id')
        ->toContain('quantity')
        ->toContain('price_override')
        ->toContain('is_included')
        ->toContain('customer_approved')
        ->toContain('customer_approved_at')
        ->toContain('sort_order');
});
