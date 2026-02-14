<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();
    $this->settings = CompanySetting::getInstance();
    $this->settings->update([
        'interior_margin_percent' => 50,
        'exterior_margin_percent' => 41,
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'marketing_percent' => 8,
        'sales_percent' => 7,
        'production_management_percent' => 7,
        'interior_cleanup_hours_ratio' => 16,
        'exterior_cleanup_hours_ratio' => 20,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
        'material_tax_rate' => 0,
        'labor_tax_rate' => 0,
    ]);
    CompanySetting::clearInstanceCache();
});

// =========================================================================
// Model: margin_discount_percent field
// =========================================================================

it('stores and retrieves margin_discount_percent', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create();

    expect((float) $estimate->margin_discount_percent)->toBe(10.0);
    expect($estimate->hasMarginDiscount())->toBeTrue();

    $estimate->refresh();
    expect((float) $estimate->margin_discount_percent)->toBe(10.0);
});

it('returns false for hasMarginDiscount when discount is zero', function () {
    $estimate = Estimate::factory()->create(['margin_discount_percent' => 0]);

    expect($estimate->hasMarginDiscount())->toBeFalse();
});

// =========================================================================
// Model: getEffectiveMarginForScope
// In PBP, margin% = COGS/price. Discount INCREASES margin% → lower price.
// =========================================================================

it('calculates effective margin for interior scope with discount', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create();

    // Interior: 50% global + 10% discount = 60% effective
    // Higher margin% = COGS is larger share of price = lower customer price
    expect($estimate->getEffectiveMarginForScope('interior'))->toBe(60.0);
});

it('calculates effective margin for exterior scope with discount', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create();

    // Exterior: 41% global + 10% discount = 51% effective
    expect($estimate->getEffectiveMarginForScope('exterior'))->toBe(51.0);
});

it('caps effective margin at 99 percent', function () {
    $estimate = Estimate::factory()->withMarginDiscount(15)->create();

    // Interior: 50 + 15 = 65 (under 99, fine)
    expect($estimate->getEffectiveMarginForScope('interior'))->toBe(65.0);
});

// =========================================================================
// Model: calculateOTDFromCOGS
// =========================================================================

it('calculates OTD from COGS using effective margin with discount', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create();

    // Interior effective margin = 50 + 10 = 60%
    // OTD = 100 / (60/100) = 100 / 0.60 = 166.67
    expect($estimate->calculateOTDFromCOGS(100, 'interior'))->toBe(166.67);
});

it('calculates standard OTD without discount', function () {
    $estimate = Estimate::factory()->create(['margin_discount_percent' => 0]);

    // Interior margin = 50%, OTD = 100 / 0.50 = 200
    expect($estimate->calculateOTDFromCOGS(100, 'interior'))->toBe(200.0);
});

// =========================================================================
// EstimateArea: getCustomerPrice with margin discount
// =========================================================================

it('returns discounted area customer price when estimate has margin discount', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create([
        'project_type' => 'interior',
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'interior',
    ]);

    // Create a surface with known COGS
    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 56.70, // 2.0 * 28.35 (21 * 1.35)
        'material_cost' => 0,
        'total' => 56.70,
        'is_included' => true,
    ]);

    // Reload with estimate relationship
    $area = $area->fresh(['estimate']);

    $cogs = $area->getFullCOGS();
    expect($cogs)->toBeGreaterThan(0);

    $customerPrice = $area->getCustomerPrice();

    // Standard price without discount: COGS / 0.50 = 2x COGS
    $standardPrice = $this->settings->calculateOTDFromCOGS($cogs, 'interior');

    // Discounted price: COGS / 0.60 (50 + 10 = 60%) ≈ 1.67x COGS
    $expectedDiscountedPrice = round($cogs / 0.60, 2);

    // Discounted price should be higher than COGS but LOWER than standard
    expect($customerPrice)->toBeGreaterThan($cogs);
    expect($customerPrice)->toBeLessThan($standardPrice);
    expect($customerPrice)->toBe($expectedDiscountedPrice);
});

it('does not affect area price when discount is zero', function () {
    $estimate = Estimate::factory()->create([
        'project_type' => 'interior',
        'margin_discount_percent' => 0,
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'interior',
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 56.70,
        'material_cost' => 0,
        'total' => 56.70,
        'is_included' => true,
    ]);

    $area = $area->fresh(['estimate']);
    $cogs = $area->getFullCOGS();
    $customerPrice = $area->getCustomerPrice();

    // Standard price: COGS / 0.50
    $standardPrice = $this->settings->calculateOTDFromCOGS($cogs, 'interior');
    expect($customerPrice)->toBe($standardPrice);
});

// =========================================================================
// Mixed scopes: interior + exterior with same discount
// =========================================================================

it('applies different effective margins per scope for mixed scope estimates', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create([
        'project_type' => 'both',
    ]);

    // Interior area: 50 + 10 = 60%
    $interiorArea = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'interior',
    ]);
    EstimateAreaSurface::factory()->forArea($interiorArea)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 50.0,
        'material_cost' => 10.0,
        'total' => 60.0,
        'is_included' => true,
    ]);

    // Exterior area: 41 + 10 = 51%
    $exteriorArea = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'exterior',
    ]);
    EstimateAreaSurface::factory()->forArea($exteriorArea)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 50.0,
        'material_cost' => 10.0,
        'total' => 60.0,
        'is_included' => true,
    ]);

    $interiorArea = $interiorArea->fresh(['estimate']);
    $exteriorArea = $exteriorArea->fresh(['estimate']);

    $interiorCogs = $interiorArea->getFullCOGS();
    $exteriorCogs = $exteriorArea->getFullCOGS();

    $interiorPrice = $interiorArea->getCustomerPrice();
    $exteriorPrice = $exteriorArea->getCustomerPrice();

    // Both should be discounted (less than standard)
    expect($interiorPrice)->toBeGreaterThan(0);
    expect($exteriorPrice)->toBeGreaterThan(0);

    // Verify the math: COGS / (effective margin / 100)
    $expectedInterior = round($interiorCogs / 0.60, 2);
    $expectedExterior = round($exteriorCogs / 0.51, 2);

    expect($interiorPrice)->toBe($expectedInterior);
    expect($exteriorPrice)->toBe($expectedExterior);

    // Interior has higher effective margin → lower price than exterior (with same COGS)
    expect($interiorPrice)->toBeLessThan($exteriorPrice);
});

// =========================================================================
// Max allowable discount
// =========================================================================

it('calculates max allowable discount keeping net profit >= 10 percent', function () {
    // Allocations: marketing 8% + sales 7% + prod mgmt 7% = 22%
    // maxEffectiveMargin = (1 - 0.22 - 0.10) * 100 = 68%
    // For interior (50%): maxDiscount = 68 - 50 = 18, capped at 15
    $estimate = Estimate::factory()->create(['project_type' => 'interior']);

    $maxDiscount = $estimate->getMaxAllowableDiscount();

    expect($maxDiscount)->toBe(15.0);
});

it('caps max allowable discount at 15 percent', function () {
    $estimate = Estimate::factory()->create(['project_type' => 'interior']);

    $maxDiscount = $estimate->getMaxAllowableDiscount();

    expect($maxDiscount)->toBeLessThanOrEqual(15.0);
});

it('calculates tighter max allowable discount for exterior scope', function () {
    // Exterior margin = 41%
    // maxEffectiveMargin = (1 - 0.22 - 0.10) * 100 = 68%
    // maxDiscount = 68 - 41 = 27, capped at 15
    $estimate = Estimate::factory()->create(['project_type' => 'exterior']);

    $maxDiscount = $estimate->getMaxAllowableDiscount('exterior');

    expect($maxDiscount)->toBe(15.0);
});

it('limits max discount when allocations are high', function () {
    // Set high allocations: 25% + 25% + 25% = 75%
    $this->settings->update([
        'marketing_percent' => 25,
        'sales_percent' => 25,
        'production_management_percent' => 25,
    ]);
    CompanySetting::clearInstanceCache();

    // maxEffectiveMargin = (1 - 0.75 - 0.10) * 100 = 15%
    // For interior (50%): maxDiscount = 15 - 50 = -35, clamped to 0
    $estimate = Estimate::factory()->create(['project_type' => 'interior']);

    $maxDiscount = $estimate->getMaxAllowableDiscount();

    expect($maxDiscount)->toBe(0.0);
});

// =========================================================================
// Fixed price areas are NOT affected
// =========================================================================

it('does not affect fixed price areas', function () {
    $estimate = Estimate::factory()->withMarginDiscount(10)->create([
        'project_type' => 'interior',
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->fixedPrice(500.0)->create();
    $area = $area->fresh(['estimate']);

    // Fixed price areas always return fixed_price_amount regardless of discount
    expect($area->getCustomerPrice())->toBe(500.0);
});

// =========================================================================
// Template builder integration
// =========================================================================

it('defaults margin discount to zero in template builder', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create([
        'estimator_mode' => 'template',
        'project_type' => 'interior',
        'margin_discount_percent' => 0,
    ]);

    $component = Livewire::test('estimates.template-builder', ['estimate' => $estimate]);

    expect($component->get('marginDiscountPercent'))->toBe(0.0);
});

it('loads margin discount when editing existing estimate', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->withMarginDiscount(8)->create([
        'estimator_mode' => 'template',
        'project_type' => 'interior',
    ]);

    $component = Livewire::test('estimates.template-builder', ['estimate' => $estimate]);

    expect($component->get('marginDiscountPercent'))->toBe(8.0);
});

// =========================================================================
// Factory state
// =========================================================================

it('uses factory withMarginDiscount state', function () {
    $estimate = Estimate::factory()->withMarginDiscount(12.5)->create();

    expect((float) $estimate->margin_discount_percent)->toBe(12.5);

    $estimate = Estimate::factory()->withMarginDiscount()->create();
    expect((float) $estimate->margin_discount_percent)->toBe(10.0);
});
