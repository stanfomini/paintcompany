<?php

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Services\EstimatePackageGeneratorService;

describe('Package selection persistence across estimate edits', function () {
    beforeEach(function () {
        $this->service = new EstimatePackageGeneratorService;
        $this->estimate = Estimate::factory()->create();
    });

    it('preserves surface deselection from a package after snapshot/restore', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Living Room']);
        $wallSurface = EstimateAreaSurface::factory()->forArea($area)->wall()->create(['name' => 'Wall Painting']);
        $ceilingSurface = EstimateAreaSurface::factory()->forArea($area)->ceiling()->create(['name' => 'Ceiling Painting']);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();

        EstimatePackageItem::withoutEvents(function () use ($package, $wallSurface) {
            EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($wallSurface)->create([
                'is_included' => true,
            ]);
        });

        EstimatePackageItem::withoutEvents(function () use ($package, $ceilingSurface) {
            EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($ceilingSurface)->create([
                'is_included' => false, // User excluded ceiling from basic package
            ]);
        });

        // Snapshot
        $snapshot = $this->service->snapshotPackageSelections($this->estimate);

        expect($snapshot)->toHaveKey('basic:Living Room:Wall Painting');
        expect($snapshot)->toHaveKey('basic:Living Room:Ceiling Painting');
        expect($snapshot['basic:Living Room:Wall Painting']['is_included'])->toBeTrue();
        expect($snapshot['basic:Living Room:Ceiling Painting']['is_included'])->toBeFalse();

        // Simulate clear and recreate
        $this->service->clearGeneratedPackages($this->estimate);

        $newArea = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Living Room']);
        $newWall = EstimateAreaSurface::factory()->forArea($newArea)->wall()->create(['name' => 'Wall Painting']);
        $newCeiling = EstimateAreaSurface::factory()->forArea($newArea)->ceiling()->create(['name' => 'Ceiling Painting']);

        $newPackage = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();

        EstimatePackageItem::withoutEvents(function () use ($newPackage, $newWall) {
            EstimatePackageItem::factory()->forPackage($newPackage)->forEstimateAreaSurface($newWall)->create([
                'is_included' => true,
            ]);
        });

        EstimatePackageItem::withoutEvents(function () use ($newPackage, $newCeiling) {
            EstimatePackageItem::factory()->forPackage($newPackage)->forEstimateAreaSurface($newCeiling)->create([
                'is_included' => true, // Default: included
            ]);
        });

        // Restore from snapshot
        $this->service->restorePackageSelections($this->estimate, $snapshot);

        $restoredItems = $newPackage->fresh()->items()->with('itemable')->get();
        $ceilingItem = $restoredItems->first(fn ($i) => $i->itemable->name === 'Ceiling Painting');
        $wallItem = $restoredItems->first(fn ($i) => $i->itemable->name === 'Wall Painting');

        expect($wallItem->is_included)->toBeTrue();
        expect($ceilingItem->is_included)->toBeFalse();
    });

    it('preserves selections across multiple package tiers', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen']);
        $surface = EstimateAreaSurface::factory()->forArea($area)->trim()->create(['name' => 'Trim & Baseboard']);

        $basic = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();
        $premium = EstimatePackage::factory()->forEstimate($this->estimate)->premium()->create();

        // Exclude trim from basic, include in premium
        EstimatePackageItem::withoutEvents(function () use ($basic, $surface) {
            EstimatePackageItem::factory()->forPackage($basic)->forEstimateAreaSurface($surface)->create([
                'is_included' => false,
            ]);
        });

        EstimatePackageItem::withoutEvents(function () use ($premium, $surface) {
            EstimatePackageItem::factory()->forPackage($premium)->forEstimateAreaSurface($surface)->create([
                'is_included' => true,
            ]);
        });

        $snapshot = $this->service->snapshotPackageSelections($this->estimate);

        expect($snapshot['basic:Kitchen:Trim & Baseboard']['is_included'])->toBeFalse();
        expect($snapshot['premium:Kitchen:Trim & Baseboard']['is_included'])->toBeTrue();

        // Simulate clear and recreate
        $this->service->clearGeneratedPackages($this->estimate);

        $newArea = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'Kitchen']);
        $newSurface = EstimateAreaSurface::factory()->forArea($newArea)->trim()->create(['name' => 'Trim & Baseboard']);

        $newBasic = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();
        $newPremium = EstimatePackage::factory()->forEstimate($this->estimate)->premium()->create();

        EstimatePackageItem::withoutEvents(function () use ($newBasic, $newSurface) {
            EstimatePackageItem::factory()->forPackage($newBasic)->forEstimateAreaSurface($newSurface)->create([
                'is_included' => true,
            ]);
        });

        EstimatePackageItem::withoutEvents(function () use ($newPremium, $newSurface) {
            EstimatePackageItem::factory()->forPackage($newPremium)->forEstimateAreaSurface($newSurface)->create([
                'is_included' => true,
            ]);
        });

        $this->service->restorePackageSelections($this->estimate, $snapshot);

        $basicItem = $newBasic->fresh()->items()->with('itemable')->first();
        $premiumItem = $newPremium->fresh()->items()->with('itemable')->first();

        expect($basicItem->is_included)->toBeFalse();
        expect($premiumItem->is_included)->toBeTrue();
    });

    it('keeps default is_included for new surfaces not in snapshot', function () {
        // Empty snapshot (no prior selections)
        $snapshot = [];

        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'New Room']);
        $surface = EstimateAreaSurface::factory()->forArea($area)->wall()->create(['name' => 'Wall Painting']);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();

        EstimatePackageItem::withoutEvents(function () use ($package, $surface) {
            EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
                'is_included' => true,
            ]);
        });

        $this->service->restorePackageSelections($this->estimate, $snapshot);

        $item = $package->fresh()->items()->first();
        expect($item->is_included)->toBeTrue();
    });

    it('does not error when snapshot references removed surfaces', function () {
        // Snapshot with surfaces that no longer exist
        $snapshot = [
            'basic:Old Room:Wall Painting' => ['is_included' => false],
            'basic:Old Room:Ceiling Painting' => ['is_included' => false],
        ];

        $area = EstimateArea::factory()->forEstimate($this->estimate)->create(['name' => 'New Room']);
        $surface = EstimateAreaSurface::factory()->forArea($area)->wall()->create(['name' => 'Wall Painting']);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->basic()->create();

        EstimatePackageItem::withoutEvents(function () use ($package, $surface) {
            EstimatePackageItem::factory()->forPackage($package)->forEstimateAreaSurface($surface)->create([
                'is_included' => true,
            ]);
        });

        // Should not throw
        $this->service->restorePackageSelections($this->estimate, $snapshot);

        $item = $package->fresh()->items()->first();
        expect($item->is_included)->toBeTrue();
    });

    it('preserves fixed-price area exclusion', function () {
        $area = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(150)->create(['name' => 'Half Bath']);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->standard()->create();

        EstimatePackageItem::withoutEvents(function () use ($package, $area) {
            EstimatePackageItem::factory()->forPackage($package)->forEstimateArea($area)->create([
                'is_included' => false, // User excluded this fixed-price area
            ]);
        });

        $snapshot = $this->service->snapshotPackageSelections($this->estimate);

        expect($snapshot)->toHaveKey('standard:area:Half Bath');
        expect($snapshot['standard:area:Half Bath']['is_included'])->toBeFalse();

        // Simulate clear and recreate
        $this->service->clearGeneratedPackages($this->estimate);

        $newArea = EstimateArea::factory()->forEstimate($this->estimate)->fixedPrice(150)->create(['name' => 'Half Bath']);
        $newPackage = EstimatePackage::factory()->forEstimate($this->estimate)->standard()->create();

        EstimatePackageItem::withoutEvents(function () use ($newPackage, $newArea) {
            EstimatePackageItem::factory()->forPackage($newPackage)->forEstimateArea($newArea)->create([
                'is_included' => true,
            ]);
        });

        $this->service->restorePackageSelections($this->estimate, $snapshot);

        $item = $newPackage->fresh()->items()->first();
        expect($item->is_included)->toBeFalse();
    });

    it('preserves service item customer_approved state', function () {
        $serviceItem = EstimateItem::factory()->forEstimate($this->estimate)->create([
            'description' => 'Power Washing',
        ]);

        $package = EstimatePackage::factory()->forEstimate($this->estimate)->premium()->create();

        EstimatePackageItem::withoutEvents(function () use ($package, $serviceItem) {
            EstimatePackageItem::factory()->forPackage($package)->forEstimateItem($serviceItem)->create([
                'is_included' => true,
                'customer_approved' => false, // Customer declined this add-on
            ]);
        });

        $snapshot = $this->service->snapshotPackageSelections($this->estimate);

        expect($snapshot)->toHaveKey('premium:service:Power Washing');
        expect($snapshot['premium:service:Power Washing']['is_included'])->toBeTrue();
        expect($snapshot['premium:service:Power Washing']['customer_approved'])->toBeFalse();

        // Simulate clear and recreate
        $this->service->clearGeneratedPackages($this->estimate);

        $newServiceItem = EstimateItem::factory()->forEstimate($this->estimate)->create([
            'description' => 'Power Washing',
        ]);

        $newPackage = EstimatePackage::factory()->forEstimate($this->estimate)->premium()->create();

        EstimatePackageItem::withoutEvents(function () use ($newPackage, $newServiceItem) {
            EstimatePackageItem::factory()->forPackage($newPackage)->forEstimateItem($newServiceItem)->create([
                'is_included' => true,
                'customer_approved' => true, // Default: approved
            ]);
        });

        $this->service->restorePackageSelections($this->estimate, $snapshot);

        $item = $newPackage->fresh()->items()->first();
        expect($item->is_included)->toBeTrue();
        expect($item->customer_approved)->toBeFalse();
    });
});

describe('Tier pricing reflects per-package surface selections', function () {
    beforeEach(function () {
        \App\Models\CompanySetting::query()->delete();
        \App\Models\CompanySetting::clearInstanceCache();
        $this->breakdownService = new \App\Services\EstimateCostBreakdownService;
    });

    it('shows different tier prices when packages have different surface selections', function () {
        $estimate = Estimate::factory()->create([
            'paint_tiers' => [
                'good' => ['default_paint_product_id' => null],
                'better' => ['default_paint_product_id' => null],
                'best' => ['default_paint_product_id' => null],
            ],
        ]);

        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Bedroom',
            'scope_type' => 'interior',
        ]);

        $wallSurface = EstimateAreaSurface::factory()->forArea($area)->wall()->create([
            'name' => 'Walls',
            'labor_hours' => 2,
            'labor_cost' => 50,
            'material_cost' => 30,
            'material_cost_good' => 30,
            'material_cost_better' => 45,
            'material_cost_best' => 60,
            'total' => 80,
        ]);

        $ceilingSurface = EstimateAreaSurface::factory()->forArea($area)->ceiling()->create([
            'name' => 'Ceiling',
            'labor_hours' => 1,
            'labor_cost' => 25,
            'material_cost' => 15,
            'material_cost_good' => 15,
            'material_cost_better' => 20,
            'material_cost_best' => 25,
            'total' => 40,
        ]);

        // Basic package: both surfaces included
        $basic = EstimatePackage::factory()->forEstimate($estimate)->basic()->create();
        EstimatePackageItem::withoutEvents(function () use ($basic, $wallSurface) {
            EstimatePackageItem::factory()->forPackage($basic)->forEstimateAreaSurface($wallSurface)->create(['is_included' => true]);
        });
        EstimatePackageItem::withoutEvents(function () use ($basic, $ceilingSurface) {
            EstimatePackageItem::factory()->forPackage($basic)->forEstimateAreaSurface($ceilingSurface)->create(['is_included' => true]);
        });

        // Standard package: only walls included (ceiling excluded)
        $standard = EstimatePackage::factory()->forEstimate($estimate)->standard()->create();
        EstimatePackageItem::withoutEvents(function () use ($standard, $wallSurface) {
            EstimatePackageItem::factory()->forPackage($standard)->forEstimateAreaSurface($wallSurface)->create(['is_included' => true]);
        });
        EstimatePackageItem::withoutEvents(function () use ($standard, $ceilingSurface) {
            EstimatePackageItem::factory()->forPackage($standard)->forEstimateAreaSurface($ceilingSurface)->create(['is_included' => false]);
        });

        // Premium package: both surfaces included
        $premium = EstimatePackage::factory()->forEstimate($estimate)->premium()->create();
        EstimatePackageItem::withoutEvents(function () use ($premium, $wallSurface) {
            EstimatePackageItem::factory()->forPackage($premium)->forEstimateAreaSurface($wallSurface)->create(['is_included' => true]);
        });
        EstimatePackageItem::withoutEvents(function () use ($premium, $ceilingSurface) {
            EstimatePackageItem::factory()->forPackage($premium)->forEstimateAreaSurface($ceilingSurface)->create(['is_included' => true]);
        });

        // Calculate breakdown from the basic package (which has all surfaces)
        $basic->update(['is_selected' => true]);
        $estimate->update(['selected_package_id' => $basic->id]);

        $breakdown = $this->breakdownService->calculateBreakdown($estimate);

        // Should have tier_pricing on the area
        $areaBreakdown = $breakdown['areas'][0];
        expect($areaBreakdown)->toHaveKey('tier_pricing');
        expect($areaBreakdown['tier_pricing'])->toHaveKeys(['good', 'better', 'best']);

        // Good (basic) and Best (premium) include both surfaces - should have higher price
        // Better (standard) only includes walls - should have lower price
        $goodPrice = $areaBreakdown['tier_pricing']['good']['customer_price'];
        $betterPrice = $areaBreakdown['tier_pricing']['better']['customer_price'];
        $bestPrice = $areaBreakdown['tier_pricing']['best']['customer_price'];

        // Better should be less than Good because it only has walls (fewer surfaces)
        expect($betterPrice)->toBeLessThan($goodPrice);

        // Good includes both surfaces, Better only walls → Better must be cheaper
        // Best includes both surfaces with best materials → Best should be most expensive
        expect($bestPrice)->toBeGreaterThan($goodPrice);
    });

    it('shows zero price for tier where all surfaces are excluded', function () {
        $estimate = Estimate::factory()->create([
            'paint_tiers' => [
                'good' => ['default_paint_product_id' => null],
                'better' => ['default_paint_product_id' => null],
                'best' => ['default_paint_product_id' => null],
            ],
        ]);

        $area = EstimateArea::factory()->forEstimate($estimate)->create([
            'name' => 'Den',
            'scope_type' => 'interior',
        ]);

        $surface = EstimateAreaSurface::factory()->forArea($area)->wall()->create([
            'name' => 'Walls',
            'labor_hours' => 2,
            'labor_cost' => 50,
            'material_cost' => 30,
            'material_cost_good' => 30,
            'material_cost_better' => 45,
            'material_cost_best' => 60,
            'total' => 80,
        ]);

        // Basic: surface included
        $basic = EstimatePackage::factory()->forEstimate($estimate)->basic()->create(['is_selected' => true]);
        EstimatePackageItem::withoutEvents(function () use ($basic, $surface) {
            EstimatePackageItem::factory()->forPackage($basic)->forEstimateAreaSurface($surface)->create(['is_included' => true]);
        });

        // Standard: surface excluded
        $standard = EstimatePackage::factory()->forEstimate($estimate)->standard()->create();
        EstimatePackageItem::withoutEvents(function () use ($standard, $surface) {
            EstimatePackageItem::factory()->forPackage($standard)->forEstimateAreaSurface($surface)->create(['is_included' => false]);
        });

        // Premium: surface included
        $premium = EstimatePackage::factory()->forEstimate($estimate)->premium()->create();
        EstimatePackageItem::withoutEvents(function () use ($premium, $surface) {
            EstimatePackageItem::factory()->forPackage($premium)->forEstimateAreaSurface($surface)->create(['is_included' => true]);
        });

        $estimate->update(['selected_package_id' => $basic->id]);

        $breakdown = $this->breakdownService->calculateBreakdown($estimate);
        $tierPricing = $breakdown['areas'][0]['tier_pricing'];

        // Good and Best should have a price (surface included)
        expect($tierPricing['good']['customer_price'])->toBeGreaterThan(0);
        expect($tierPricing['best']['customer_price'])->toBeGreaterThan(0);

        // Better should be zero (all surfaces excluded from that package)
        expect($tierPricing['better']['customer_price'])->toBe(0.0);
        expect($tierPricing['better']['cogs'])->toBe(0.0);
    });
});
