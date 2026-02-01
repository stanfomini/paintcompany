<?php

use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\EstimateSignature;

it('can create an estimate package', function () {
    $package = EstimatePackage::factory()->create();

    expect($package)->toBeInstanceOf(EstimatePackage::class)
        ->and($package->id)->toBeGreaterThan(0)
        ->and($package->estimate_id)->toBeGreaterThan(0);
});

it('belongs to an estimate', function () {
    $estimate = Estimate::factory()->create();
    $package = EstimatePackage::factory()->forEstimate($estimate)->create();

    expect($package->estimate)->toBeInstanceOf(Estimate::class)
        ->and($package->estimate->id)->toBe($estimate->id);
});

it('belongs to a signature when signed', function () {
    $signature = EstimateSignature::factory()->create();
    $package = EstimatePackage::factory()->create([
        'signature_id' => $signature->id,
        'signed_at' => now(),
    ]);

    expect($package->signature)->toBeInstanceOf(EstimateSignature::class)
        ->and($package->signature->id)->toBe($signature->id);
});

it('has many items', function () {
    $package = EstimatePackage::factory()->create();
    $item = EstimateItem::factory()->create([
        'estimate_id' => $package->estimate_id,
    ]);

    EstimatePackageItem::factory()->create([
        'package_id' => $package->id,
        'itemable_type' => EstimateItem::class,
        'itemable_id' => $item->id,
    ]);

    expect($package->items)->toHaveCount(1)
        ->and($package->items->first())->toBeInstanceOf(EstimatePackageItem::class);
});

it('has tier constants', function () {
    expect(EstimatePackage::TIER_BASIC)->toBe('basic')
        ->and(EstimatePackage::TIER_STANDARD)->toBe('standard')
        ->and(EstimatePackage::TIER_PREMIUM)->toBe('premium')
        ->and(EstimatePackage::TIER_CUSTOM)->toBe('custom');
});

it('has tier labels', function () {
    expect(EstimatePackage::TIER_LABELS)->toBe([
        'basic' => 'Good',
        'standard' => 'Better',
        'premium' => 'Best',
        'custom' => 'Custom',
    ]);
});

it('returns correct tier label attribute', function () {
    $basicPackage = EstimatePackage::factory()->basic()->create();
    $standardPackage = EstimatePackage::factory()->standard()->create();
    $premiumPackage = EstimatePackage::factory()->premium()->create();
    $customPackage = EstimatePackage::factory()->custom()->create();

    expect($basicPackage->tier_label)->toBe('Good')
        ->and($standardPackage->tier_label)->toBe('Better')
        ->and($premiumPackage->tier_label)->toBe('Best')
        ->and($customPackage->tier_label)->toBe('Custom');
});

it('casts money fields as decimals', function () {
    $package = EstimatePackage::factory()->create([
        'subtotal' => 1000.50,
        'tax_rate' => 7.25,
        'tax_amount' => 72.54,
        'discount_amount' => 100.00,
        'discount_percent' => 10.00,
        'total' => 973.04,
        'warranty_price' => 250.00,
    ]);

    expect($package->subtotal)->toBe('1000.50')
        ->and($package->tax_rate)->toBe('7.25')
        ->and($package->tax_amount)->toBe('72.54')
        ->and($package->discount_amount)->toBe('100.00')
        ->and($package->discount_percent)->toBe('10.00')
        ->and($package->total)->toBe('973.04')
        ->and($package->warranty_price)->toBe('250.00');
});

it('casts boolean fields correctly', function () {
    $package = EstimatePackage::factory()->create([
        'is_recommended' => true,
        'is_selected' => false,
    ]);

    expect($package->is_recommended)->toBeTrue()
        ->and($package->is_selected)->toBeFalse();
});

it('casts signed_at as datetime', function () {
    $signedAt = now();
    $package = EstimatePackage::factory()->create([
        'signed_at' => $signedAt,
    ]);

    expect($package->signed_at)->toBeInstanceOf(DateTimeInterface::class);
});

it('calculates totals from included items', function () {
    $estimate = Estimate::factory()->create();

    $package = EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
        'tax_rate' => 10,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'warranty_price' => 0,
    ]);

    // Clear any existing items
    EstimatePackageItem::where('package_id', $package->id)->delete();

    // Create estimate items with specific quantity and unit_price to get desired totals
    // (EstimateItem model calculates total = quantity * unit_price on save)
    $item1 = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 500,
    ]);
    $item2 = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 300,
    ]);

    // Add items to package (without triggering calculateTotals via boot)
    EstimatePackageItem::withoutEvents(function () use ($package, $item1, $item2) {
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item1->id,
            'is_included' => true,
            'price_override' => null,
            'customer_approved' => true,
            'sort_order' => 0,
        ]);
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item2->id,
            'is_included' => true,
            'price_override' => null,
            'customer_approved' => true,
            'sort_order' => 1,
        ]);
    });

    // Refresh the items relationship
    $package->load('items');

    $package->calculateTotals();

    expect((float) $package->subtotal)->toBe(800.0)
        ->and((float) $package->tax_amount)->toBe(80.0)
        ->and((float) $package->total)->toBe(880.0);
});

it('excludes non-included items from totals', function () {
    $estimate = Estimate::factory()->create();

    $package = EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
        'tax_rate' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'warranty_price' => 0,
    ]);

    // Clear any existing items
    EstimatePackageItem::where('package_id', $package->id)->delete();

    $item1 = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 500,
    ]);
    $item2 = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 300,
    ]);

    EstimatePackageItem::withoutEvents(function () use ($package, $item1, $item2) {
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item1->id,
            'is_included' => true,
            'price_override' => null,
            'customer_approved' => true,
            'sort_order' => 0,
        ]);
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item2->id,
            'is_included' => false,
            'price_override' => null,
            'customer_approved' => true,
            'sort_order' => 1,
        ]);
    });

    $package->load('items');
    $package->calculateTotals();

    expect((float) $package->subtotal)->toBe(500.0)
        ->and((float) $package->total)->toBe(500.0);
});

it('applies discount percent to totals', function () {
    $estimate = Estimate::factory()->create();

    $package = EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
        'tax_rate' => 0,
        'discount_percent' => 10,
        'discount_amount' => 0,
        'warranty_price' => 0,
    ]);

    // Clear any existing items
    EstimatePackageItem::where('package_id', $package->id)->delete();

    $item = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 1000,
    ]);

    EstimatePackageItem::withoutEvents(function () use ($package, $item) {
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'price_override' => null,
            'customer_approved' => true,
            'sort_order' => 0,
        ]);
    });

    $package->load('items');
    $package->calculateTotals();

    expect((float) $package->subtotal)->toBe(1000.0)
        ->and((float) $package->discount_amount)->toBe(100.0)
        ->and((float) $package->total)->toBe(900.0);
});

it('includes warranty price in totals', function () {
    $estimate = Estimate::factory()->create();

    $package = EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
        'tax_rate' => 10,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'warranty_price' => 200,
    ]);

    // Clear any existing items
    EstimatePackageItem::where('package_id', $package->id)->delete();

    $item = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 1000,
    ]);

    EstimatePackageItem::withoutEvents(function () use ($package, $item) {
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'price_override' => null,
            'customer_approved' => true,
            'sort_order' => 0,
        ]);
    });

    $package->load('items');
    $package->calculateTotals();

    // Subtotal is 1000 (items only)
    // Base amount with warranty: 1000 + 200 = 1200
    // Tax: 1200 * 0.10 = 120
    // Total: 1200 + 120 = 1320
    expect((float) $package->subtotal)->toBe(1000.0)
        ->and((float) $package->tax_amount)->toBe(120.0)
        ->and((float) $package->total)->toBe(1320.0);
});

it('calculates deposit amount using estimate deposit percent', function () {
    $estimate = Estimate::factory()->create(['deposit_percent' => 25]);
    $package = EstimatePackage::factory()->forEstimate($estimate)->create([
        'total' => 1000,
    ]);

    expect($package->deposit_amount)->toBe(250.0);
});

it('uses default 30% deposit when estimate relationship is missing', function () {
    $package = EstimatePackage::factory()->create([
        'total' => 1000,
    ]);

    // Manually set estimate to null to simulate missing relationship
    $package->setRelation('estimate', null);

    expect($package->deposit_amount)->toBe(300.0);
});

it('calculates balance due correctly', function () {
    $estimate = Estimate::factory()->create(['deposit_percent' => 30]);
    $package = EstimatePackage::factory()->forEstimate($estimate)->create([
        'total' => 1000,
    ]);

    expect($package->deposit_amount)->toBe(300.0)
        ->and($package->balance_due)->toBe(700.0);
});

it('scopes to selected packages', function () {
    EstimatePackage::factory()->count(3)->create(['is_selected' => false]);
    EstimatePackage::factory()->count(2)->create(['is_selected' => true]);

    expect(EstimatePackage::selected()->count())->toBe(2);
});

it('scopes to recommended packages', function () {
    EstimatePackage::factory()->count(3)->create(['is_recommended' => false]);
    EstimatePackage::factory()->count(2)->create(['is_recommended' => true]);

    expect(EstimatePackage::recommended()->count())->toBe(2);
});

it('can use factory tier states', function () {
    $basic = EstimatePackage::factory()->basic()->create();
    $standard = EstimatePackage::factory()->standard()->create();
    $premium = EstimatePackage::factory()->premium()->create();
    $custom = EstimatePackage::factory()->custom()->create();

    expect($basic->tier)->toBe('basic')
        ->and($basic->name)->toBe('Good Package')
        ->and($standard->tier)->toBe('standard')
        ->and($standard->name)->toBe('Better Package')
        ->and($premium->tier)->toBe('premium')
        ->and($premium->name)->toBe('Best Package')
        ->and($custom->tier)->toBe('custom')
        ->and($custom->name)->toBe('Custom Package');
});

it('can use factory recommended state', function () {
    $package = EstimatePackage::factory()->recommended()->create();

    expect($package->is_recommended)->toBeTrue();
});

it('can use factory selected state', function () {
    $package = EstimatePackage::factory()->selected()->create();

    expect($package->is_selected)->toBeTrue();
});

it('can use factory withPricing state', function () {
    $package = EstimatePackage::factory()
        ->withPricing(subtotal: 1000, taxRate: 8, discountPercent: 10)
        ->create();

    expect((float) $package->subtotal)->toBe(1000.0)
        ->and((float) $package->tax_rate)->toBe(8.0)
        ->and((float) $package->discount_percent)->toBe(10.0)
        ->and((float) $package->discount_amount)->toBe(100.0)
        ->and((float) $package->tax_amount)->toBe(72.0)
        ->and((float) $package->total)->toBe(972.0);
});

it('can use factory withWarranty state', function () {
    $package = EstimatePackage::factory()
        ->withWarranty(price: 350, years: 3, type: 'labor')
        ->create();

    expect((float) $package->warranty_price)->toBe(350.0)
        ->and($package->warranty_years)->toBe(3)
        ->and($package->warranty_type)->toBe('labor');
});

it('uses price override when set on package items', function () {
    $estimate = Estimate::factory()->create();

    $package = EstimatePackage::factory()->create([
        'estimate_id' => $estimate->id,
        'tax_rate' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'warranty_price' => 0,
    ]);

    // Clear any existing items
    EstimatePackageItem::where('package_id', $package->id)->delete();

    $item = EstimateItem::factory()->create([
        'estimate_id' => $estimate->id,
        'quantity' => 1,
        'unit_price' => 1000,
    ]);

    EstimatePackageItem::withoutEvents(function () use ($package, $item) {
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'price_override' => 750,
            'customer_approved' => true,
            'sort_order' => 0,
        ]);
    });

    $package->load('items');
    $package->calculateTotals();

    expect((float) $package->subtotal)->toBe(750.0)
        ->and((float) $package->total)->toBe(750.0);
});

it('orders items by sort_order', function () {
    $package = EstimatePackage::factory()->create();

    $item1 = EstimateItem::factory()->create(['estimate_id' => $package->estimate_id]);
    $item2 = EstimateItem::factory()->create(['estimate_id' => $package->estimate_id]);
    $item3 = EstimateItem::factory()->create(['estimate_id' => $package->estimate_id]);

    EstimatePackageItem::factory()->create([
        'package_id' => $package->id,
        'itemable_type' => EstimateItem::class,
        'itemable_id' => $item1->id,
        'sort_order' => 3,
    ]);
    EstimatePackageItem::factory()->create([
        'package_id' => $package->id,
        'itemable_type' => EstimateItem::class,
        'itemable_id' => $item2->id,
        'sort_order' => 1,
    ]);
    EstimatePackageItem::factory()->create([
        'package_id' => $package->id,
        'itemable_type' => EstimateItem::class,
        'itemable_id' => $item3->id,
        'sort_order' => 2,
    ]);

    $orderedItems = $package->items;

    expect($orderedItems[0]->sort_order)->toBe(1)
        ->and($orderedItems[1]->sort_order)->toBe(2)
        ->and($orderedItems[2]->sort_order)->toBe(3);
});
