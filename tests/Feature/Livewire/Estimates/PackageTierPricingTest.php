<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\EstimatePackageItem;
use App\Models\User;
use App\Services\EstimatePackageService;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->deal = Deal::factory()->create();

    // Set up consistent company settings for predictable pricing
    $settings = CompanySetting::getInstance();
    $settings->update([
        'base_hourly_wage' => 21.00,
        'payroll_burden_multiplier' => 1.35,
        'margin_percent' => 50,
        'markup_percent' => 50,
        'interior_hourly_wage' => 21.00,
        'interior_margin_percent' => 50,
        'interior_markup_percent' => 50,
        'interior_cleanup_hours_ratio' => 16,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_hourly_wage' => 27.00,
        'exterior_margin_percent' => 41,
        'exterior_markup_percent' => 41,
        'exterior_cleanup_hours_ratio' => 20,
        'exterior_sundries_cost_per_hour' => 1.00,
        'material_tax_rate' => 7.5,
        'labor_tax_rate' => 0,
        'cleanup_hours_ratio' => 16,
        'sundries_cost_per_prep_hour' => 3.30,
        'recycle_fee_per_gallon' => 0.54,
        'net_profit_margin_percent' => 25,
        'overhead_percent' => 19,
        'marketing_percent' => 8,
        'sales_percent' => 7,
        'production_management_percent' => 7,
    ]);

    // Create estimate with paint tiers
    $this->estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'created_by' => $this->user->id,
        'status' => 'draft',
        'estimator_mode' => 'template',
        'project_type' => 'interior',
        'tax_rate' => 0,
        'paint_tiers' => [
            'good' => ['default_paint_product_id' => 1],
            'better' => ['default_paint_product_id' => 2],
            'best' => ['default_paint_product_id' => 3],
        ],
    ]);

    $this->area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'scope_type' => 'interior',
        'is_fixed_price' => false,
    ]);

    // Wall surface with different material costs per tier
    $this->wallSurface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Wall Painting',
        'surface_type' => 'wall',
        'quantity' => 300,
        'unit' => 'sqft',
        'production_rate_value' => 300,
        'coats' => 2,
        'hourly_labor_rate' => 21.00 * 1.35,
        'labor_hours' => 1.80,
        'labor_cost' => 1.80 * 21.00 * 1.35,
        'material_cost' => 50.00,
        'total' => (1.80 * 21.00 * 1.35) + 50.00,
        'is_included' => true,
        'is_custom' => false,
        'material_cost_good' => 30.00,
        'material_cost_better' => 50.00,
        'material_cost_best' => 80.00,
    ]);

    // Ceiling surface with different material costs per tier
    $this->ceilingSurface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'name' => 'Ceiling Painting',
        'surface_type' => 'ceiling',
        'quantity' => 200,
        'unit' => 'sqft',
        'production_rate_value' => 400,
        'coats' => 1,
        'hourly_labor_rate' => 21.00 * 1.35,
        'labor_hours' => 0.50,
        'labor_cost' => 0.50 * 21.00 * 1.35,
        'material_cost' => 35.00,
        'total' => (0.50 * 21.00 * 1.35) + 35.00,
        'is_included' => true,
        'is_custom' => false,
        'material_cost_good' => 20.00,
        'material_cost_better' => 35.00,
        'material_cost_best' => 55.00,
    ]);
});

it('packages have different totals when paint tiers are enabled', function () {
    // Create 3 packages with different tiers
    $goodPackage = EstimatePackage::factory()->basic()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);
    $betterPackage = EstimatePackage::factory()->standard()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);
    $bestPackage = EstimatePackage::factory()->premium()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);

    // Add surfaces to all packages
    foreach ([$goodPackage, $betterPackage, $bestPackage] as $package) {
        foreach ([$this->wallSurface, $this->ceilingSurface] as $surface) {
            EstimatePackageItem::create([
                'package_id' => $package->id,
                'itemable_type' => EstimateAreaSurface::class,
                'itemable_id' => $surface->id,
                'quantity' => $surface->quantity,
                'is_included' => true,
                'customer_approved' => true,
                'customer_approved_at' => now(),
                'sort_order' => 1,
            ]);
        }
    }

    // Calculate totals via the service (which delegates to model)
    $service = app(EstimatePackageService::class);
    $service->calculatePackageTotal($goodPackage);
    $service->calculatePackageTotal($betterPackage);
    $service->calculatePackageTotal($bestPackage);

    $goodPackage->refresh();
    $betterPackage->refresh();
    $bestPackage->refresh();

    // Good (basic) uses cheapest materials → lowest total
    // Better (standard) uses mid-range materials → middle total
    // Best (premium) uses most expensive materials → highest total
    expect((float) $goodPackage->subtotal)->toBeLessThan((float) $betterPackage->subtotal);
    expect((float) $betterPackage->subtotal)->toBeLessThan((float) $bestPackage->subtotal);
});

it('packages have identical totals when no paint tiers configured', function () {
    // Remove paint tiers
    $this->estimate->paint_tiers = null;
    $this->estimate->save();

    // Create 3 packages with different tiers
    $goodPackage = EstimatePackage::factory()->basic()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);
    $betterPackage = EstimatePackage::factory()->standard()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);
    $bestPackage = EstimatePackage::factory()->premium()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);

    // Add surfaces to all packages
    foreach ([$goodPackage, $betterPackage, $bestPackage] as $package) {
        foreach ([$this->wallSurface, $this->ceilingSurface] as $surface) {
            EstimatePackageItem::create([
                'package_id' => $package->id,
                'itemable_type' => EstimateAreaSurface::class,
                'itemable_id' => $surface->id,
                'quantity' => $surface->quantity,
                'is_included' => true,
                'customer_approved' => true,
                'customer_approved_at' => now(),
                'sort_order' => 1,
            ]);
        }
    }

    // Calculate totals
    $service = app(EstimatePackageService::class);
    $service->calculatePackageTotal($goodPackage);
    $service->calculatePackageTotal($betterPackage);
    $service->calculatePackageTotal($bestPackage);

    $goodPackage->refresh();
    $betterPackage->refresh();
    $bestPackage->refresh();

    // Without tiers, all packages use the default material_cost → same total
    expect((float) $goodPackage->subtotal)->toBe((float) $betterPackage->subtotal);
    expect((float) $betterPackage->subtotal)->toBe((float) $bestPackage->subtotal);
});

it('getSurfaceCustomerPrices with tier sums to getCustomerPrice with tier', function () {
    foreach (['good', 'better', 'best'] as $tier) {
        $surfacePrices = $this->area->getSurfaceCustomerPrices($tier);
        $totalFromSurfaces = array_sum($surfacePrices);
        $areaPrice = $this->area->getCustomerPrice($tier);

        // Surface prices should sum to the area's customer price (within rounding tolerance)
        expect(abs($totalFromSurfaces - $areaPrice))->toBeLessThan(0.02,
            "Tier '{$tier}': surface prices ({$totalFromSurfaces}) should sum to area price ({$areaPrice})"
        );
    }
});

it('service calculatePackageTotal produces same result as model calculateTotals', function () {
    $package = EstimatePackage::factory()->standard()->forEstimate($this->estimate)->create([
        'subtotal' => 0, 'tax_rate' => 0, 'tax_amount' => 0, 'total' => 0,
        'discount_percent' => 0, 'discount_amount' => 0, 'warranty_price' => 0,
    ]);

    foreach ([$this->wallSurface, $this->ceilingSurface] as $surface) {
        EstimatePackageItem::create([
            'package_id' => $package->id,
            'itemable_type' => EstimateAreaSurface::class,
            'itemable_id' => $surface->id,
            'quantity' => $surface->quantity,
            'is_included' => true,
            'customer_approved' => true,
            'customer_approved_at' => now(),
            'sort_order' => 1,
        ]);
    }

    // Calculate via model method directly
    $package->calculateTotals();
    $modelSubtotal = (float) $package->subtotal;
    $modelTotal = (float) $package->total;

    // Reset and calculate via service
    $package->subtotal = 0;
    $package->total = 0;
    $service = app(EstimatePackageService::class);
    $service->calculatePackageTotal($package);
    $package->refresh();

    expect((float) $package->subtotal)->toBe($modelSubtotal);
    expect((float) $package->total)->toBe($modelTotal);
});

it('painter breakdown profitSummary changes when viewingTier is set', function () {
    $this->actingAs($this->user);

    $component = Livewire\Livewire::test('estimates.painter-breakdown', ['estimate' => $this->estimate]);

    // Default viewingTier is 'better' (set in mount)
    $component->assertSet('viewingTier', 'better');

    $betterSummary = $component->get('profitSummary');

    // Switch to 'good' tier
    $component->call('selectViewingTier', 'good');
    $goodSummary = $component->get('profitSummary');

    // Switch to 'best' tier
    $component->call('selectViewingTier', 'best');
    $bestSummary = $component->get('profitSummary');

    // Good should have lower job price than Better, which should be lower than Best
    expect($goodSummary['job_price'])->toBeLessThan($betterSummary['job_price']);
    expect($betterSummary['job_price'])->toBeLessThan($bestSummary['job_price']);

    // Verify the tier label is included in the summary
    expect($goodSummary['viewing_tier'])->toBe('good');
    expect($betterSummary['viewing_tier'])->toBe('better');
    expect($bestSummary['viewing_tier'])->toBe('best');
});

it('getSurfaceCustomerPrices without tier returns same as before', function () {
    // Test backward compatibility - null tier should use default material_cost
    $pricesDefault = $this->area->getSurfaceCustomerPrices();
    $pricesNull = $this->area->getSurfaceCustomerPrices(null);

    expect($pricesDefault)->toBe($pricesNull);

    // Should have entries for both surfaces
    expect($pricesDefault)->toHaveCount(2);
    expect($pricesDefault)->toHaveKey($this->wallSurface->id);
    expect($pricesDefault)->toHaveKey($this->ceilingSurface->id);
});
