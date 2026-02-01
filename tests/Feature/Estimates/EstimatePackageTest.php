<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\User;
use App\Services\EstimatePackageService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('EstimatePackage calculations', function () {
    it('calculates totals by summing included and approved items', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        // Create estimate items with known prices
        $item1 = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total' => 500,
        ]);

        $item2 = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 2,
            'unit_price' => 250,
            'total' => 500,
        ]);

        // Add items to package as included and approved
        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item1->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item2->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        // Refresh and recalculate
        $package->refresh();
        $package->calculateTotals();

        expect((float) $package->subtotal)->toBe(1000.0);
        expect((float) $package->total)->toBe(1000.0);
    });

    it('excludes non-included items from total', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        $includedItem = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 600,
            'total' => 600,
        ]);

        $excludedItem = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 400,
            'total' => 400,
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $includedItem->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        EstimatePackageItem::factory()->excluded()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $excludedItem->id,
            'customer_approved' => true,
        ]);

        $package->refresh();
        $package->calculateTotals();

        expect((float) $package->subtotal)->toBe(600.0);
    });

    it('excludes non-approved items from total', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        $approvedItem = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 800,
            'total' => 800,
        ]);

        $notApprovedItem = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 200,
            'total' => 200,
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $approvedItem->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        EstimatePackageItem::factory()->notApproved()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $notApprovedItem->id,
            'is_included' => true,
        ]);

        $package->refresh();
        $package->calculateTotals();

        expect((float) $package->subtotal)->toBe(800.0);
    });

    it('calculates deposit and balance correctly', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'deposit_percent' => 30,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'subtotal' => 0,
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        // Add item with price of 1000
        $item = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'total' => 1000,
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        $package->refresh();
        $package->calculateTotals();

        // Total is 1000, deposit is 30% = 300, balance is 700
        expect((float) $package->total)->toBe(1000.0);
        expect($package->deposit_amount)->toBe(300.0);
        expect($package->balance_due)->toBe(700.0);
    });

    it('applies discount percent before tax', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 10,
            'discount_percent' => 20,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        // Add item with price of 1000
        $item = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'total' => 1000,
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        $package->refresh();
        $package->calculateTotals();

        // Subtotal: 1000
        // Discount (20%): 200
        // Taxable amount: 800
        // Tax (10%): 80
        // Total: 880
        expect((float) $package->subtotal)->toBe(1000.0);
        expect((float) $package->discount_amount)->toBe(200.0);
        expect((float) $package->tax_amount)->toBe(80.0);
        expect((float) $package->total)->toBe(880.0);
    });

    it('includes warranty price in calculations', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 10,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 200,
        ]);

        $item = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 800,
            'total' => 800,
        ]);

        EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        $package->refresh();
        $package->calculateTotals();

        // Subtotal: 800 (items)
        // Base amount: 800 + 200 (warranty) = 1000
        // Tax (10%): 100
        // Total: 1100
        expect((float) $package->subtotal)->toBe(800.0);
        expect((float) $package->tax_amount)->toBe(100.0);
        expect((float) $package->total)->toBe(1100.0);
    });
});

describe('EstimatePackageService', function () {
    it('creates good better best packages', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
            'tax_rate' => 8,
        ]);

        // Add some items to the estimate
        EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total' => 500,
        ]);

        $packages = $service->createGoodBetterBestPackages($estimate);

        expect($packages)->toHaveCount(3);

        $goodPackage = $packages[0];
        $betterPackage = $packages[1];
        $bestPackage = $packages[2];

        expect($goodPackage->name)->toBe('Good');
        expect($goodPackage->tier)->toBe(EstimatePackage::TIER_BASIC);
        expect($goodPackage->is_recommended)->toBeFalse();

        expect($betterPackage->name)->toBe('Better');
        expect($betterPackage->tier)->toBe(EstimatePackage::TIER_STANDARD);
        expect($betterPackage->is_recommended)->toBeTrue();

        expect($bestPackage->name)->toBe('Best');
        expect($bestPackage->tier)->toBe(EstimatePackage::TIER_PREMIUM);
        expect($bestPackage->is_recommended)->toBeFalse();
    });

    it('selects a package and deselects others', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package1 = EstimatePackage::factory()
            ->forEstimate($estimate)
            ->selected()
            ->create();

        $package2 = EstimatePackage::factory()
            ->forEstimate($estimate)
            ->create(['is_selected' => false]);

        // Select package2
        $service->selectPackage($estimate, $package2);

        $package1->refresh();
        $package2->refresh();
        $estimate->refresh();

        expect($package1->is_selected)->toBeFalse();
        expect($package2->is_selected)->toBeTrue();
        expect($estimate->selected_package_id)->toBe($package2->id);
    });

    it('throws exception when selecting package from different estimate', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal1 = Deal::factory()->create();
        $deal2 = Deal::factory()->create();

        $estimate1 = Estimate::factory()->forDeal($deal1)->create([
            'created_by' => $user->id,
        ]);

        $estimate2 = Estimate::factory()->forDeal($deal2)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()
            ->forEstimate($estimate2)
            ->create();

        expect(fn () => $service->selectPackage($estimate1, $package))
            ->toThrow(\InvalidArgumentException::class, 'The package does not belong to this estimate.');
    });

    it('approves line item and recalculates total', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        $item = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total' => 500,
        ]);

        // Create as not approved
        $packageItem = EstimatePackageItem::factory()->notApproved()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
        ]);

        // Verify initial state - total should be 0 since item is not approved
        $package->refresh();
        $package->calculateTotals();
        expect((float) $package->total)->toBe(0.0);

        // Approve the item
        $service->approveLineItem($packageItem);

        $packageItem->refresh();
        $package->refresh();

        expect($packageItem->customer_approved)->toBeTrue();
        expect($packageItem->customer_approved_at)->not->toBeNull();
        expect((float) $package->total)->toBe(500.0);
    });

    it('rejects line item and excludes from total', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        $item1 = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 600,
            'total' => 600,
        ]);

        $item2 = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 400,
            'total' => 400,
        ]);

        EstimatePackageItem::factory()->approved()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item1->id,
            'is_included' => true,
        ]);

        $packageItem2 = EstimatePackageItem::factory()->approved()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item2->id,
            'is_included' => true,
        ]);

        // Initial total should be 1000
        $package->refresh();
        $package->calculateTotals();
        expect((float) $package->total)->toBe(1000.0);

        // Reject item2
        $service->rejectLineItem($packageItem2);

        $packageItem2->refresh();
        $package->refresh();

        expect($packageItem2->customer_approved)->toBeFalse();
        expect($packageItem2->customer_approved_at)->toBeNull();
        expect((float) $package->total)->toBe(600.0);
    });

    it('adds item to package', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        $item = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 2,
            'unit_price' => 150,
            'total' => 300,
        ]);

        $packageItem = $service->addItemToPackage($package, $item);

        expect($packageItem->package_id)->toBe($package->id);
        expect($packageItem->itemable_type)->toBe(EstimateItem::class);
        expect($packageItem->itemable_id)->toBe($item->id);
        expect($packageItem->is_included)->toBeTrue();
        expect($packageItem->customer_approved)->toBeTrue();
    });

    it('removes item from package', function () {
        $service = new EstimatePackageService;

        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $package = EstimatePackage::factory()->forEstimate($estimate)->create([
            'tax_rate' => 0,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'warranty_price' => 0,
        ]);

        $item = EstimateItem::factory()->create([
            'estimate_id' => $estimate->id,
            'quantity' => 1,
            'unit_price' => 500,
            'total' => 500,
        ]);

        $packageItem = EstimatePackageItem::factory()->create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item->id,
            'is_included' => true,
            'customer_approved' => true,
        ]);

        $packageItemId = $packageItem->id;

        $service->removeItemFromPackage($package, $packageItem);

        expect(EstimatePackageItem::find($packageItemId))->toBeNull();
    });
});

describe('EstimatePackage tier labels', function () {
    it('returns correct tier labels', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        $basicPackage = EstimatePackage::factory()->basic()->forEstimate($estimate)->create();
        $standardPackage = EstimatePackage::factory()->standard()->forEstimate($estimate)->create();
        $premiumPackage = EstimatePackage::factory()->premium()->forEstimate($estimate)->create();
        $customPackage = EstimatePackage::factory()->custom()->forEstimate($estimate)->create();

        expect($basicPackage->tier_label)->toBe('Good');
        expect($standardPackage->tier_label)->toBe('Better');
        expect($premiumPackage->tier_label)->toBe('Best');
        expect($customPackage->tier_label)->toBe('Custom');
    });
});

describe('EstimatePackage scopes', function () {
    it('filters selected packages', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        EstimatePackage::factory()->forEstimate($estimate)->selected()->create();
        EstimatePackage::factory()->forEstimate($estimate)->create(['is_selected' => false]);
        EstimatePackage::factory()->forEstimate($estimate)->create(['is_selected' => false]);

        $selected = EstimatePackage::selected()->get();

        expect($selected)->toHaveCount(1);
        expect($selected->first()->is_selected)->toBeTrue();
    });

    it('filters recommended packages', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->forDeal($deal)->create([
            'created_by' => $user->id,
        ]);

        EstimatePackage::factory()->forEstimate($estimate)->recommended()->create();
        EstimatePackage::factory()->forEstimate($estimate)->create(['is_recommended' => false]);

        $recommended = EstimatePackage::recommended()->get();

        expect($recommended)->toHaveCount(1);
        expect($recommended->first()->is_recommended)->toBeTrue();
    });
});
