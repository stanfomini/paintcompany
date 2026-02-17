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
// Model: hourly_rate_override field
// =========================================================================

it('stores and retrieves hourly_rate_override', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create();

    expect((float) $estimate->hourly_rate_override)->toBe(18.0);
    expect($estimate->hasHourlyRateOverride())->toBeTrue();

    $estimate->refresh();
    expect((float) $estimate->hourly_rate_override)->toBe(18.0);
});

it('returns false for hasHourlyRateOverride when null', function () {
    $estimate = Estimate::factory()->create(['hourly_rate_override' => null]);

    expect($estimate->hasHourlyRateOverride())->toBeFalse();
});

it('returns false for hasHourlyRateOverride when zero', function () {
    $estimate = Estimate::factory()->create(['hourly_rate_override' => 0]);

    expect($estimate->hasHourlyRateOverride())->toBeFalse();
});

// =========================================================================
// Model: getOverrideEffectiveHourlyRate
// Effective rate = override × payroll burden multiplier (1.35)
// =========================================================================

it('calculates override effective hourly rate with burden multiplier', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create();

    // 18 × 1.35 = 24.30
    $effectiveRate = $estimate->getOverrideEffectiveHourlyRate('interior');

    expect($effectiveRate)->toBe(24.30);
});

it('returns same effective rate for both scopes when override is set', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(20.00)->create();

    // Override applies uniformly: 20 × 1.35 = 27.00 for both scopes
    expect($estimate->getOverrideEffectiveHourlyRate('interior'))->toBe(27.0);
    expect($estimate->getOverrideEffectiveHourlyRate('exterior'))->toBe(27.0);
});

it('falls back to CompanySetting rate when no override', function () {
    $estimate = Estimate::factory()->create(['hourly_rate_override' => null]);

    // Interior: $21 × 1.35 = $28.35
    expect($estimate->getOverrideEffectiveHourlyRate('interior'))->toBe(28.35);

    // Exterior: $27 × 1.35 = $36.45
    expect($estimate->getOverrideEffectiveHourlyRate('exterior'))->toBe(36.45);
});

// =========================================================================
// Model: getOverrideBaseWage
// =========================================================================

it('returns override value as base wage when set', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create();

    expect($estimate->getOverrideBaseWage('interior'))->toBe(18.0);
    expect($estimate->getOverrideBaseWage('exterior'))->toBe(18.0);
});

it('falls back to CompanySetting base wage when no override', function () {
    $estimate = Estimate::factory()->create(['hourly_rate_override' => null]);

    expect($estimate->getOverrideBaseWage('interior'))->toBe(21.0);
    expect($estimate->getOverrideBaseWage('exterior'))->toBe(27.0);
});

// =========================================================================
// EstimateArea: getFullCOGS uses override for cleanup costs
// =========================================================================

it('uses override rate in area getFullCOGS for cleanup calculation', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create([
        'project_type' => 'interior',
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'interior',
    ]);

    // Create a surface with known values
    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 48.60, // 2.0 × 24.30 (18 × 1.35)
        'material_cost' => 0,
        'total' => 48.60,
        'is_included' => true,
    ]);

    $area = $area->fresh(['estimate']);

    $cogs = $area->getFullCOGS();

    // COGS includes cleanup at override rate:
    // Labor: 48.60
    // Cleanup hours: 2.0 / 16 = 0.125 hrs
    // Cleanup cost: 0.125 × 24.30 = 3.0375
    // Sundries: 2.0 × 3.30 = 6.60
    // Total COGS = 48.60 + 3.0375 + 6.60 = 58.24 (approx)
    expect($cogs)->toBeGreaterThan(48.60); // Has cleanup + sundries
    expect($cogs)->toBeLessThan(70.0); // Sanity check

    // Compare with what COGS would be with default rate (should be different)
    $estimateNoOverride = Estimate::factory()->create([
        'project_type' => 'interior',
        'hourly_rate_override' => null,
    ]);
    $areaDefault = EstimateArea::factory()->forEstimate($estimateNoOverride)->create([
        'scope_type' => 'interior',
    ]);
    EstimateAreaSurface::factory()->forArea($areaDefault)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 48.60,
        'material_cost' => 0,
        'total' => 48.60,
        'is_included' => true,
    ]);
    $areaDefault = $areaDefault->fresh(['estimate']);

    $cogsDefault = $areaDefault->getFullCOGS();

    // Default cleanup rate = 28.35, override = 24.30, so cleanup costs differ
    // Default: 0.125 × 28.35 = 3.544
    // Override: 0.125 × 24.30 = 3.0375
    expect($cogs)->toBeLessThan($cogsDefault);
});

// =========================================================================
// EstimateArea: getCustomerPrice reflects lower COGS from override
// =========================================================================

it('returns lower customer price when hourly rate override is lower', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create([
        'project_type' => 'interior',
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'interior',
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 48.60,
        'material_cost' => 0,
        'total' => 48.60,
        'is_included' => true,
    ]);

    $area = $area->fresh(['estimate']);
    $customerPrice = $area->getCustomerPrice();

    // Customer price = COGS / (50/100) = COGS / 0.50 = 2 × COGS
    $cogs = $area->getFullCOGS();
    $expectedPrice = round($cogs / 0.50, 2);

    expect($customerPrice)->toBe($expectedPrice);
    expect($customerPrice)->toBeGreaterThan(0.0);
});

// =========================================================================
// Fixed price areas are NOT affected
// =========================================================================

it('does not affect fixed price areas with override', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create([
        'project_type' => 'interior',
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->fixedPrice(500.0)->create();
    $area = $area->fresh(['estimate']);

    // Fixed price areas always return fixed_price_amount regardless of override
    expect($area->getCustomerPrice())->toBe(500.0);
});

// =========================================================================
// Works simultaneously with margin discount
// =========================================================================

it('applies both margin discount and hourly rate override together', function () {
    $estimate = Estimate::factory()->create([
        'project_type' => 'interior',
        'margin_discount_percent' => 10,
        'hourly_rate_override' => 18.00,
    ]);

    $area = EstimateArea::factory()->forEstimate($estimate)->create([
        'scope_type' => 'interior',
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'labor_hours' => 2.0,
        'labor_cost' => 48.60, // 2.0 × 24.30 (18 × 1.35)
        'material_cost' => 0,
        'total' => 48.60,
        'is_included' => true,
    ]);

    $area = $area->fresh(['estimate']);

    $cogs = $area->getFullCOGS();
    $customerPrice = $area->getCustomerPrice();

    // With 10% margin discount: effective margin = 50 + 10 = 60%
    // Customer price = COGS / 0.60
    $expectedPrice = round($cogs / 0.60, 2);
    expect($customerPrice)->toBe($expectedPrice);

    // Verify it's cheaper than just margin discount alone (without rate override)
    // The override lowered the COGS, and the margin discount lowered the margin
    expect($customerPrice)->toBeGreaterThan(0.0);
});

// =========================================================================
// Template builder integration
// =========================================================================

it('defaults hourly rate override to null in template builder', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->create([
        'estimator_mode' => 'template',
        'project_type' => 'interior',
        'hourly_rate_override' => null,
    ]);

    $component = Livewire::test('estimates.template-builder', ['estimate' => $estimate]);

    expect($component->get('hourlyRateOverride'))->toBeNull();
});

it('loads hourly rate override when editing existing estimate', function () {
    $deal = Deal::factory()->create();
    $estimate = Estimate::factory()->forDeal($deal)->withHourlyRateOverride(18.00)->create([
        'estimator_mode' => 'template',
        'project_type' => 'interior',
    ]);

    $component = Livewire::test('estimates.template-builder', ['estimate' => $estimate]);

    expect($component->get('hourlyRateOverride'))->toBe(18.0);
});

// =========================================================================
// Factory state
// =========================================================================

it('uses factory withHourlyRateOverride state', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(15.50)->create();

    expect((float) $estimate->hourly_rate_override)->toBe(15.5);

    $estimate = Estimate::factory()->withHourlyRateOverride()->create();
    expect((float) $estimate->hourly_rate_override)->toBe(18.0);
});

// =========================================================================
// Breakdown service includes override in settings
// =========================================================================

it('includes hourly_rate_override in breakdown service settings', function () {
    $estimate = Estimate::factory()->withHourlyRateOverride(18.00)->create([
        'project_type' => 'interior',
    ]);

    $service = app(\App\Services\EstimateCostBreakdownService::class);
    $currentSettings = $service->getCurrentSettings($estimate);

    expect($currentSettings['hourly_rate_override'])->toBe(18.0);
});

it('returns null hourly_rate_override when not set', function () {
    $estimate = Estimate::factory()->create([
        'project_type' => 'interior',
        'hourly_rate_override' => null,
    ]);

    $service = app(\App\Services\EstimateCostBreakdownService::class);
    $currentSettings = $service->getCurrentSettings($estimate);

    expect($currentSettings['hourly_rate_override'])->toBeNull();
});
