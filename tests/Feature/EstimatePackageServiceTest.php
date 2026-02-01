<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Services\EstimatePackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new EstimatePackageService;
});

describe('createPackage', function () {
    it('creates a package with default tier', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 8.5]);

        $package = $this->service->createPackage($estimate, 'Test Package');

        expect($package)
            ->toBeInstanceOf(EstimatePackage::class)
            ->name->toBe('Test Package')
            ->tier->toBe(EstimatePackage::TIER_STANDARD)
            ->tax_rate->toBe('8.50')
            ->subtotal->toBe('0.00')
            ->total->toBe('0.00')
            ->is_recommended->toBeFalse()
            ->is_selected->toBeFalse()
            ->estimate_id->toBe($estimate->id);
    });

    it('creates a package with specified tier', function () {
        $estimate = Estimate::factory()->create();

        $package = $this->service->createPackage($estimate, 'Premium Package', EstimatePackage::TIER_PREMIUM);

        expect($package->tier)->toBe(EstimatePackage::TIER_PREMIUM);
    });

    it('increments sort order for multiple packages', function () {
        $estimate = Estimate::factory()->create();

        $package1 = $this->service->createPackage($estimate, 'Package 1');
        $package2 = $this->service->createPackage($estimate, 'Package 2');
        $package3 = $this->service->createPackage($estimate, 'Package 3');

        expect($package1->sort_order)->toBe(1);
        expect($package2->sort_order)->toBe(2);
        expect($package3->sort_order)->toBe(3);
    });
});

describe('duplicateEstimateToPackage', function () {
    it('copies estimate items to package', function () {
        $estimate = Estimate::factory()->create();
        $item1 = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'total' => 100]);
        $item2 = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'total' => 200]);

        $package = $this->service->createPackage($estimate, 'Test Package');
        $this->service->duplicateEstimateToPackage($estimate, $package);

        expect($package->items)->toHaveCount(2);
        expect($package->items->pluck('itemable_id')->toArray())->toContain($item1->id, $item2->id);
        expect($package->items->first()->is_included)->toBeTrue();
        expect($package->items->first()->customer_approved)->toBeTrue();
    });

    it('copies estimate areas and surfaces to package', function () {
        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        $surface1 = EstimateAreaSurface::factory()->create(['area_id' => $area->id, 'total' => 150]);
        $surface2 = EstimateAreaSurface::factory()->create(['area_id' => $area->id, 'total' => 250]);

        $package = $this->service->createPackage($estimate, 'Test Package');
        $this->service->duplicateEstimateToPackage($estimate, $package);

        // Should have 1 area + 2 surfaces = 3 items
        expect($package->items)->toHaveCount(3);

        $areaItems = $package->items->where('itemable_type', EstimateArea::class);
        $surfaceItems = $package->items->where('itemable_type', EstimateAreaSurface::class);

        expect($areaItems)->toHaveCount(1);
        expect($surfaceItems)->toHaveCount(2);
    });

    it('marks all copied items as included and approved', function () {
        $estimate = Estimate::factory()->create();
        EstimateItem::factory()->count(3)->create(['estimate_id' => $estimate->id]);

        $package = $this->service->createPackage($estimate, 'Test Package');
        $this->service->duplicateEstimateToPackage($estimate, $package);

        foreach ($package->items as $item) {
            expect($item->is_included)->toBeTrue();
            expect($item->customer_approved)->toBeTrue();
            expect($item->customer_approved_at)->not->toBeNull();
        }
    });
});

describe('addItemToPackage', function () {
    it('adds an EstimateItem to a package', function () {
        $estimate = Estimate::factory()->create();
        $estimateItem = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 5]);
        $package = $this->service->createPackage($estimate, 'Test Package');

        $packageItem = $this->service->addItemToPackage($package, $estimateItem);

        expect($packageItem)
            ->toBeInstanceOf(EstimatePackageItem::class)
            ->itemable_type->toBe(EstimateItem::class)
            ->itemable_id->toBe($estimateItem->id)
            ->quantity->toBe('5.00')
            ->is_included->toBeTrue()
            ->customer_approved->toBeTrue();
    });

    it('adds an EstimateArea to a package', function () {
        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        $package = $this->service->createPackage($estimate, 'Test Package');

        $packageItem = $this->service->addItemToPackage($package, $area);

        expect($packageItem)
            ->itemable_type->toBe(EstimateArea::class)
            ->itemable_id->toBe($area->id)
            ->quantity->toBeNull();
    });

    it('adds an EstimateAreaSurface to a package', function () {
        $estimate = Estimate::factory()->create();
        $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
        $surface = EstimateAreaSurface::factory()->create(['area_id' => $area->id, 'quantity' => 150]);
        $package = $this->service->createPackage($estimate, 'Test Package');

        $packageItem = $this->service->addItemToPackage($package, $surface);

        expect($packageItem)
            ->itemable_type->toBe(EstimateAreaSurface::class)
            ->itemable_id->toBe($surface->id)
            ->quantity->toBe('150.00');
    });

    it('applies price override when provided', function () {
        $estimate = Estimate::factory()->create();
        $estimateItem = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'total' => 500]);
        $package = $this->service->createPackage($estimate, 'Test Package');

        $packageItem = $this->service->addItemToPackage($package, $estimateItem, 350.00);

        expect($packageItem->price_override)->toBe('350.00');
        expect($packageItem->effective_price)->toBe(350.00);
    });

    it('throws exception for invalid item type', function () {
        $estimate = Estimate::factory()->create();
        $package = $this->service->createPackage($estimate, 'Test Package');

        $this->service->addItemToPackage($package, $estimate);
    })->throws(InvalidArgumentException::class);
});

describe('removeItemFromPackage', function () {
    it('removes an item from a package', function () {
        $estimate = Estimate::factory()->create();
        $estimateItem = EstimateItem::factory()->create(['estimate_id' => $estimate->id]);
        $package = $this->service->createPackage($estimate, 'Test Package');
        $packageItem = $this->service->addItemToPackage($package, $estimateItem);

        expect($package->items)->toHaveCount(1);

        $this->service->removeItemFromPackage($package, $packageItem);

        $package->refresh();
        expect($package->items)->toHaveCount(0);
    });

    it('throws exception if item does not belong to package', function () {
        $estimate = Estimate::factory()->create();
        $package1 = $this->service->createPackage($estimate, 'Package 1');
        $package2 = $this->service->createPackage($estimate, 'Package 2');

        $estimateItem = EstimateItem::factory()->create(['estimate_id' => $estimate->id]);
        $packageItem = $this->service->addItemToPackage($package1, $estimateItem);

        $this->service->removeItemFromPackage($package2, $packageItem);
    })->throws(InvalidArgumentException::class);
});

describe('calculatePackageTotal', function () {
    it('calculates subtotal from approved items only', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 0]);
        $item1 = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 100]);
        $item2 = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 200]);

        $package = $this->service->createPackage($estimate, 'Test Package');

        // Add approved item using service
        $this->service->addItemToPackage($package, $item1);

        // Add non-approved item manually (bypass service to test rejection)
        $packageItem2 = EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $item2->id,
            'is_included' => true,
            'customer_approved' => false,
            'sort_order' => 2,
        ]);

        $this->service->calculatePackageTotal($package);

        expect($package->subtotal)->toBe('100.00');
        expect($package->total)->toBe('100.00');
    });

    it('applies tax rate correctly', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 10]);
        $item = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 1000]);

        $package = $this->service->createPackage($estimate, 'Test Package');
        $package->tax_rate = 10;
        $package->save();

        $this->service->addItemToPackage($package, $item);

        $package->refresh();

        expect($package->subtotal)->toBe('1000.00');
        expect($package->tax_amount)->toBe('100.00');
        expect($package->total)->toBe('1100.00');
    });

    it('applies discount amount correctly', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 0]);
        $item = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 1000]);

        $package = $this->service->createPackage($estimate, 'Test Package');
        $package->discount_amount = 100;
        $package->save();

        $this->service->addItemToPackage($package, $item);

        $package->refresh();

        expect($package->subtotal)->toBe('1000.00');
        expect($package->discount_amount)->toBe('100.00');
        expect($package->total)->toBe('900.00');
    });

    it('applies discount percent correctly', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 0]);
        $item = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 1000]);

        $package = $this->service->createPackage($estimate, 'Test Package');
        $package->discount_percent = 10;
        $package->save();

        $this->service->addItemToPackage($package, $item);

        $package->refresh();

        expect($package->subtotal)->toBe('1000.00');
        expect($package->discount_amount)->toBe('100.00');
        expect($package->total)->toBe('900.00');
    });
});

describe('selectPackage', function () {
    it('selects a package and deselects others', function () {
        $estimate = Estimate::factory()->create();
        $package1 = $this->service->createPackage($estimate, 'Package 1');
        $package2 = $this->service->createPackage($estimate, 'Package 2');
        $package3 = $this->service->createPackage($estimate, 'Package 3');

        $package1->is_selected = true;
        $package1->save();

        $this->service->selectPackage($estimate, $package2);

        $package1->refresh();
        $package2->refresh();
        $package3->refresh();
        $estimate->refresh();

        expect($package1->is_selected)->toBeFalse();
        expect($package2->is_selected)->toBeTrue();
        expect($package3->is_selected)->toBeFalse();
        expect($estimate->selected_package_id)->toBe($package2->id);
    });

    it('throws exception if package does not belong to estimate', function () {
        $estimate1 = Estimate::factory()->create();
        $estimate2 = Estimate::factory()->create();
        $package = $this->service->createPackage($estimate2, 'Test Package');

        $this->service->selectPackage($estimate1, $package);
    })->throws(InvalidArgumentException::class);
});

describe('approveLineItem', function () {
    it('approves a line item and sets timestamp', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 0]);
        $estimateItem = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 500]);

        $package = $this->service->createPackage($estimate, 'Test Package');

        // Create a non-approved item manually
        $packageItem = EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateItem::class,
            'itemable_id' => $estimateItem->id,
            'is_included' => true,
            'customer_approved' => false,
            'customer_approved_at' => null,
            'sort_order' => 1,
        ]);

        $this->service->approveLineItem($packageItem);

        $packageItem->refresh();
        $package->refresh();

        expect($packageItem->customer_approved)->toBeTrue();
        expect($packageItem->customer_approved_at)->not->toBeNull();
        expect($package->subtotal)->toBe('500.00');
    });
});

describe('rejectLineItem', function () {
    it('rejects a line item and clears timestamp', function () {
        $estimate = Estimate::factory()->create(['tax_rate' => 0]);
        $estimateItem = EstimateItem::factory()->create(['estimate_id' => $estimate->id, 'quantity' => 1, 'unit_price' => 500]);

        $package = $this->service->createPackage($estimate, 'Test Package');

        // Add item using service (approved by default)
        $packageItem = $this->service->addItemToPackage($package, $estimateItem);

        // Verify it's approved initially
        expect($packageItem->customer_approved)->toBeTrue();
        expect($package->fresh()->subtotal)->toBe('500.00');

        $this->service->rejectLineItem($packageItem);

        $packageItem->refresh();
        $package->refresh();

        expect($packageItem->customer_approved)->toBeFalse();
        expect($packageItem->customer_approved_at)->toBeNull();
        expect($package->subtotal)->toBe('0.00');
    });
});

describe('createGoodBetterBestPackages', function () {
    it('creates three packages with correct tiers', function () {
        $estimate = Estimate::factory()->create();

        $packages = $this->service->createGoodBetterBestPackages($estimate);

        expect($packages)->toHaveCount(3);
        expect($packages[0]->tier)->toBe(EstimatePackage::TIER_BASIC);
        expect($packages[1]->tier)->toBe(EstimatePackage::TIER_STANDARD);
        expect($packages[2]->tier)->toBe(EstimatePackage::TIER_PREMIUM);
    });

    it('sets Better package as recommended', function () {
        $estimate = Estimate::factory()->create();

        $packages = $this->service->createGoodBetterBestPackages($estimate);

        expect($packages[0]->is_recommended)->toBeFalse();
        expect($packages[1]->is_recommended)->toBeTrue();
        expect($packages[2]->is_recommended)->toBeFalse();
    });

    it('assigns correct sort orders', function () {
        $estimate = Estimate::factory()->create();

        $packages = $this->service->createGoodBetterBestPackages($estimate);

        expect($packages[0]->sort_order)->toBe(1);
        expect($packages[1]->sort_order)->toBe(2);
        expect($packages[2]->sort_order)->toBe(3);
    });

    it('duplicates estimate items to all packages', function () {
        $estimate = Estimate::factory()->create();
        EstimateItem::factory()->count(3)->create(['estimate_id' => $estimate->id]);

        $packages = $this->service->createGoodBetterBestPackages($estimate);

        foreach ($packages as $package) {
            expect($package->items)->toHaveCount(3);
        }
    });

    it('sets descriptions for each package', function () {
        $estimate = Estimate::factory()->create();

        $packages = $this->service->createGoodBetterBestPackages($estimate);

        expect($packages[0]->description)->not->toBeNull();
        expect($packages[1]->description)->not->toBeNull();
        expect($packages[2]->description)->not->toBeNull();
    });
});
