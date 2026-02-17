<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimatePackage;
use App\Models\User;
use App\Services\EstimateTierPricingService;

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

    // Create estimate with area and surfaces
    $this->estimate = Estimate::factory()->create([
        'deal_id' => $this->deal->id,
        'created_by' => $this->user->id,
        'status' => 'draft',
        'estimator_mode' => 'template',
        'project_type' => 'interior',
        'tax_rate' => 0,
    ]);

    $this->area = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'scope_type' => 'interior',
        'is_fixed_price' => false,
    ]);

    // Wall surface - base material cost $50
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
        // Tier material costs
        'material_cost_good' => 30.00,
        'material_cost_better' => 50.00,
        'material_cost_best' => 80.00,
    ]);

    // Ceiling surface - base material cost $35
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
        // Tier material costs
        'material_cost_good' => 20.00,
        'material_cost_better' => 35.00,
        'material_cost_best' => 55.00,
    ]);
});

// =========================================================================
// BACKWARD COMPATIBILITY TESTS
// =========================================================================

it('getFullCOGS with null tier matches default getFullCOGS for surfaces', function () {
    $defaultCogs = $this->wallSurface->getFullCOGS();
    $nullTierCogs = $this->wallSurface->getFullCOGS(null);

    expect($defaultCogs)->toBe($nullTierCogs);
});

it('getFullCOGS with null tier matches default getFullCOGS for areas', function () {
    $defaultCogs = $this->area->getFullCOGS();
    $nullTierCogs = $this->area->getFullCOGS(null);

    expect($defaultCogs)->toBe($nullTierCogs);
});

it('getCustomerPrice with null tier matches default for areas', function () {
    $defaultPrice = $this->area->getCustomerPrice();
    $nullTierPrice = $this->area->getCustomerPrice(null);

    expect($defaultPrice)->toBe($nullTierPrice);
});

it('getCustomerPrice with null tier matches default for surfaces', function () {
    $defaultPrice = $this->wallSurface->getCustomerPrice();
    $nullTierPrice = $this->wallSurface->getCustomerPrice(null);

    expect($defaultPrice)->toBe($nullTierPrice);
});

it('getPartialCustomerPrice with null tier matches default', function () {
    $surfaceIds = [$this->wallSurface->id];
    $defaultPrice = $this->area->getPartialCustomerPrice($surfaceIds);
    $nullTierPrice = $this->area->getPartialCustomerPrice($surfaceIds, null);

    expect($defaultPrice)->toBe($nullTierPrice);
});

// =========================================================================
// TIER MATERIAL COST TESTS
// =========================================================================

it('getMaterialCostForTier returns tier column value when set', function () {
    expect($this->wallSurface->getMaterialCostForTier('good'))->toBe(30.0);
    expect($this->wallSurface->getMaterialCostForTier('better'))->toBe(50.0);
    expect($this->wallSurface->getMaterialCostForTier('best'))->toBe(80.0);
});

it('getMaterialCostForTier falls back to material_cost when tier column is null', function () {
    $surface = EstimateAreaSurface::factory()->create([
        'area_id' => $this->area->id,
        'material_cost' => 42.50,
        'material_cost_good' => null,
        'material_cost_better' => null,
        'material_cost_best' => null,
        'is_included' => true,
    ]);

    expect($surface->getMaterialCostForTier('good'))->toBe(42.5);
    expect($surface->getMaterialCostForTier('better'))->toBe(42.5);
    expect($surface->getMaterialCostForTier('best'))->toBe(42.5);
});

it('getMaterialCostForTier returns standard material_cost for null tier', function () {
    expect($this->wallSurface->getMaterialCostForTier(null))->toBe(50.0);
});

// =========================================================================
// TIER COGS ORDERING TESTS
// =========================================================================

it('good tier COGS is less than better, better is less than best', function () {
    $goodCogs = $this->wallSurface->getFullCOGS('good');
    $betterCogs = $this->wallSurface->getFullCOGS('better');
    $bestCogs = $this->wallSurface->getFullCOGS('best');

    expect($goodCogs)->toBeLessThan($betterCogs);
    expect($betterCogs)->toBeLessThan($bestCogs);
});

it('area customer price increases with tier', function () {
    $goodPrice = $this->area->getCustomerPrice('good');
    $betterPrice = $this->area->getCustomerPrice('better');
    $bestPrice = $this->area->getCustomerPrice('best');

    expect($goodPrice)->toBeLessThan($betterPrice);
    expect($betterPrice)->toBeLessThan($bestPrice);
});

// =========================================================================
// SAME LABOR ACROSS TIERS
// =========================================================================

it('labor hours are identical regardless of tier', function () {
    // Labor is stored on the surface and doesn't change with tier.
    // Only material cost differs. Verify COGS difference = material cost difference.
    $goodCogs = $this->wallSurface->getFullCOGS('good');
    $bestCogs = $this->wallSurface->getFullCOGS('best');

    // The difference in COGS between good and best should be due to materials only.
    // Material cost good = 30, best = 80, difference = 50.
    // With 7.5% material tax: (80 - 30) * 1.075 = 53.75
    $materialDiff = ($this->wallSurface->material_cost_best - $this->wallSurface->material_cost_good);
    $settings = CompanySetting::getInstance();
    $materialTaxRate = $settings->getMaterialTaxRate();
    $expectedCogsDiff = $materialDiff * (1 + $materialTaxRate / 100);

    expect(round($bestCogs - $goodCogs, 2))->toBe(round($expectedCogsDiff, 2));
});

// =========================================================================
// PACKAGE TIER MAPPING
// =========================================================================

it('getPaintTier returns correct mapping for each package tier', function () {
    $basic = EstimatePackage::factory()->basic()->create(['estimate_id' => $this->estimate->id]);
    $standard = EstimatePackage::factory()->standard()->create(['estimate_id' => $this->estimate->id]);
    $premium = EstimatePackage::factory()->premium()->create(['estimate_id' => $this->estimate->id]);
    $custom = EstimatePackage::factory()->custom()->create(['estimate_id' => $this->estimate->id]);

    expect($basic->getPaintTier())->toBe('good');
    expect($standard->getPaintTier())->toBe('better');
    expect($premium->getPaintTier())->toBe('best');
    expect($custom->getPaintTier())->toBeNull();
});

// =========================================================================
// ESTIMATE PAINT TIER METHODS
// =========================================================================

it('hasPaintTiers returns false for null or empty', function () {
    expect($this->estimate->hasPaintTiers())->toBeFalse();

    $this->estimate->paint_tiers = [];
    expect($this->estimate->hasPaintTiers())->toBeFalse();
});

it('hasPaintTiers returns true for populated JSON', function () {
    $this->estimate->paint_tiers = [
        'good' => ['default_paint_product_id' => 1],
        'better' => ['default_paint_product_id' => 2],
        'best' => ['default_paint_product_id' => 3],
    ];
    expect($this->estimate->hasPaintTiers())->toBeTrue();
});

it('getPaintTierConfig returns config for known tier', function () {
    $this->estimate->paint_tiers = [
        'good' => ['default_paint_product_id' => 1, 'label' => 'Economy'],
    ];

    $config = $this->estimate->getPaintTierConfig('good');
    expect($config)->toBe(['default_paint_product_id' => 1, 'label' => 'Economy']);
});

it('getPaintTierConfig returns null for unknown tier', function () {
    $this->estimate->paint_tiers = [
        'good' => ['default_paint_product_id' => 1],
    ];

    expect($this->estimate->getPaintTierConfig('ultra'))->toBeNull();
});

it('setPaintTier stores tier config', function () {
    $this->estimate->setPaintTier('good', ['default_paint_product_id' => 5]);
    $this->estimate->setPaintTier('best', ['default_paint_product_id' => 10]);

    expect($this->estimate->paint_tiers)->toBe([
        'good' => ['default_paint_product_id' => 5],
        'best' => ['default_paint_product_id' => 10],
    ]);
});

it('calculateAreasSubtotalForTier returns different totals per tier', function () {
    $goodTotal = $this->estimate->calculateAreasSubtotalForTier('good');
    $betterTotal = $this->estimate->calculateAreasSubtotalForTier('better');
    $bestTotal = $this->estimate->calculateAreasSubtotalForTier('best');

    expect($goodTotal)->toBeLessThan($betterTotal);
    expect($betterTotal)->toBeLessThan($bestTotal);
});

// =========================================================================
// PARTIAL CUSTOMER PRICE WITH TIER
// =========================================================================

it('getPartialCustomerPrice works correctly with tier parameter', function () {
    // Only include wall surface
    $wallOnly = $this->area->getPartialCustomerPrice(
        [$this->wallSurface->id],
        'good'
    );

    // Full good price
    $fullGood = $this->area->getCustomerPrice('good');

    // Partial should be less than full (since we excluded ceiling)
    expect($wallOnly)->toBeLessThan($fullGood);
    expect($wallOnly)->toBeGreaterThan(0);
});

it('getPartialCustomerPrice returns full price when all surfaces included with tier', function () {
    $allIds = [$this->wallSurface->id, $this->ceilingSurface->id];
    $partialGood = $this->area->getPartialCustomerPrice($allIds, 'good');
    $fullGood = $this->area->getCustomerPrice('good');

    expect($partialGood)->toBe($fullGood);
});

// =========================================================================
// FIXED PRICE AREAS ARE UNAFFECTED BY TIER
// =========================================================================

it('fixed price areas return same price regardless of tier', function () {
    $fixedArea = EstimateArea::factory()->create([
        'estimate_id' => $this->estimate->id,
        'scope_type' => 'interior',
        'is_fixed_price' => true,
        'fixed_price_amount' => 500.00,
    ]);

    expect($fixedArea->getCustomerPrice())->toBe(500.0);
    expect($fixedArea->getCustomerPrice('good'))->toBe(500.0);
    expect($fixedArea->getCustomerPrice('better'))->toBe(500.0);
    expect($fixedArea->getCustomerPrice('best'))->toBe(500.0);
});

// =========================================================================
// ESTIMATE TIER PRICING SERVICE
// =========================================================================

it('clearTierCosts clears all tier data from surfaces', function () {
    $service = new EstimateTierPricingService;

    $cleared = $service->clearTierCosts($this->estimate);

    expect($cleared)->toBe(2); // Both surfaces had tier data

    $this->wallSurface->refresh();
    expect($this->wallSurface->material_cost_good)->toBeNull();
    expect($this->wallSurface->material_cost_better)->toBeNull();
    expect($this->wallSurface->material_cost_best)->toBeNull();
    expect($this->wallSurface->paint_product_id_good)->toBeNull();
    expect($this->wallSurface->paint_product_id_better)->toBeNull();
    expect($this->wallSurface->paint_product_id_best)->toBeNull();
});

it('clearTierCosts returns 0 for surfaces with no tier data', function () {
    $service = new EstimateTierPricingService;

    // First clear, then try again
    $service->clearTierCosts($this->estimate);
    $clearedAgain = $service->clearTierCosts($this->estimate);

    expect($clearedAgain)->toBe(0);
});

it('calculateTierTotals returns same base total when no tiers configured', function () {
    $service = new EstimateTierPricingService;

    // Ensure estimate has no paint_tiers
    $this->estimate->paint_tiers = null;
    $this->estimate->save();

    // First calculate the areas_subtotal fresh
    $this->estimate->calculateTotals();
    $this->estimate->save();

    $totals = $service->calculateTierTotals($this->estimate);

    $baseTotal = (float) $this->estimate->areas_subtotal;
    expect($totals['good'])->toBe($baseTotal);
    expect($totals['better'])->toBe($baseTotal);
    expect($totals['best'])->toBe($baseTotal);
});

it('calculateTierTotals returns different totals when tiers are configured', function () {
    $service = new EstimateTierPricingService;

    // Set up tiers on the estimate (even simple config triggers hasPaintTiers)
    $this->estimate->paint_tiers = [
        'good' => ['label' => 'Economy'],
        'better' => ['label' => 'Standard'],
        'best' => ['label' => 'Premium'],
    ];
    $this->estimate->save();

    $totals = $service->calculateTierTotals($this->estimate);

    expect($totals['good'])->toBeLessThan($totals['better']);
    expect($totals['better'])->toBeLessThan($totals['best']);
});

// =========================================================================
// PACKAGE calculateTotals WITH TIERS
// =========================================================================

it('package calculateTotals uses tier pricing when estimate has paint tiers', function () {
    // Set up tiers
    $this->estimate->paint_tiers = [
        'good' => ['label' => 'Economy'],
        'better' => ['label' => 'Standard'],
        'best' => ['label' => 'Premium'],
    ];
    $this->estimate->save();

    // Create packages for each tier
    $basicPackage = EstimatePackage::factory()->basic()->create([
        'estimate_id' => $this->estimate->id,
        'tax_rate' => 0,
        'warranty_price' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
    ]);

    $premiumPackage = EstimatePackage::factory()->premium()->create([
        'estimate_id' => $this->estimate->id,
        'tax_rate' => 0,
        'warranty_price' => 0,
        'discount_percent' => 0,
        'discount_amount' => 0,
    ]);

    // Add area items to both packages
    foreach ([$basicPackage, $premiumPackage] as $package) {
        \App\Models\EstimatePackageItem::withoutEvents(function () use ($package) {
            \App\Models\EstimatePackageItem::create([
                'package_id' => $package->id,
                'itemable_type' => EstimateArea::class,
                'itemable_id' => $this->area->id,
                'quantity' => 1,
                'is_included' => true,
                'customer_approved' => true,
                'customer_approved_at' => now(),
                'sort_order' => 0,
            ]);
        });
    }

    $basicPackage->calculateTotals();
    $premiumPackage->calculateTotals();

    // Basic (good tier) should be cheaper than premium (best tier)
    expect((float) $basicPackage->subtotal)->toBeLessThan((float) $premiumPackage->subtotal);
});
