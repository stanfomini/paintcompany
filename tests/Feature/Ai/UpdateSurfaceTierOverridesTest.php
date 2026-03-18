<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Services\EstimateAiService;

beforeEach(function () {
    $this->service = new EstimateAiService;
    $this->estimate = Estimate::factory()->create([
        'status' => 'draft',
        'project_type' => 'interior',
    ]);

    CompanySetting::firstOrCreate([], [
        'company_name' => 'Test Painting Co',
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_margin_percent' => 50.00,
        'exterior_margin_percent' => 41.00,
        'interior_cleanup_hours_ratio' => 16.00,
        'exterior_cleanup_hours_ratio' => 20.00,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
        'recycle_fee_per_gallon' => 0.54,
        'material_tax_rate' => 7.50,
        'labor_tax_rate' => 0.00,
    ]);

    CompanySetting::clearInstanceCache();

    $this->rate = ProductionRate::factory()->create([
        'name' => 'Smooth Walls',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 200,
        'unit_type' => 'sqft',
        'rate_type' => 'sqft_hr',
        'default_coats' => 2,
        'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
    ]);

    $this->area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Living Room',
        'scope_type' => 'interior',
        'length' => 14,
        'width' => 12,
        'height' => 9,
        'wall_sqft' => 468,
        'ceiling_sqft' => 168,
    ]);

    $this->surface = EstimateAreaSurface::factory()->forArea($this->area)->forRate($this->rate)->create([
        'name' => 'Wall Painting',
        'surface_type' => 'wall',
        'surface_category' => 'walls',
        'quantity' => 468,
        'unit' => 'sqft',
        'coats' => 2,
    ]);
});

it('rejects invalid tier names', function () {
    $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'premium',
    );
})->throws(\InvalidArgumentException::class, 'Invalid tier: premium');

it('creates base snapshot on first tier override', function () {
    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        coats: 3,
    );

    $surface = $this->surface->fresh();
    $overrides = $surface->tier_overrides;

    expect($overrides)->toHaveKey('_base')
        ->and($overrides['_base']['production_rate_id'])->toBe($this->rate->id)
        ->and($overrides['_base']['default_coats'])->toBe(2)
        ->and($overrides)->toHaveKey('best')
        ->and($overrides['best']['default_coats'])->toBe(3);
});

it('sets coats override for a specific tier', function () {
    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        coats: 3,
    );

    expect($result['tier'])->toBe('best')
        ->and($result['tier_data']['default_coats'])->toBe(3)
        ->and($result['tier_data']['labor_hours'])->toBeGreaterThan(0);

    // Best with 3 coats should have more labor than base with 2 coats
    $baseLaborHours = (float) $this->surface->labor_hours;
    expect($result['tier_data']['labor_hours'])->toBeGreaterThan($baseLaborHours);
});

it('auto-computes labor hours when coats change', function () {
    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'good',
        coats: 1,
    );

    // 1 coat should have less labor than 2 coats
    $expectedHours = $this->rate->calculateLaborHours(468, 1);
    expect($result['tier_data']['labor_hours'])->toBe(round($expectedHours, 4));
});

it('auto-computes labor hours when production rate changes', function () {
    $fasterRate = ProductionRate::factory()->create([
        'name' => 'Fast Spray Walls',
        'scope' => 'interior',
        'surface_category' => 'walls',
        'production_rate' => 400,
        'unit_type' => 'sqft',
        'rate_type' => 'sqft_hr',
        'default_coats' => 2,
        'coat_multipliers' => ['1' => 1.0, '2' => 1.8, '3' => 2.5],
    ]);

    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'good',
        productionRateId: $fasterRate->id,
    );

    // Faster rate should result in fewer labor hours
    $expectedHours = $fasterRate->calculateLaborHours(468, 2);
    expect($result['tier_data']['labor_hours'])->toBe(round($expectedHours, 4))
        ->and($result['tier_data']['production_rate_id'])->toBe($fasterRate->id);
});

it('uses explicit labor hours when provided', function () {
    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'better',
        laborHours: 5.0,
    );

    expect($result['tier_data']['labor_hours'])->toBe(5.0)
        ->and($result['tier_data']['labor_cost'])->toBeGreaterThan(0);
});

it('dual-writes material cost to flat columns and tier overrides', function () {
    $brand = PaintBrand::factory()->create(['name' => 'Test Brand']);
    $paint = PaintProduct::factory()->create([
        'paint_brand_id' => $brand->id,
        'name' => 'Premium Paint',
        'scope_type' => 'interior',
        'price_per_gallon' => 80.00,
        'coverage_sqft_brush_roll' => 400,
    ]);

    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        paintProductId: $paint->id,
    );

    $surface = $this->surface->fresh();

    // Check flat column
    expect((float) $surface->material_cost_best)->toBeGreaterThan(0)
        ->and($surface->paint_product_id_best)->toBe($paint->id);

    // Check tier_overrides JSON
    expect($surface->tier_overrides['best']['paint_product_id'])->toBe($paint->id)
        ->and($surface->tier_overrides['best']['material_cost'])->toBeGreaterThan(0);

    // Both should match
    expect((float) $surface->material_cost_best)->toBe($surface->tier_overrides['best']['material_cost']);
});

it('uses explicit material cost when provided', function () {
    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'good',
        materialCost: 42.50,
    );

    $surface = $this->surface->fresh();
    expect($result['tier_data']['material_cost'])->toBe(42.50)
        ->and((float) $surface->material_cost_good)->toBe(42.50);
});

it('clears cached area pricing after tier override', function () {
    // Pre-populate cached values
    $this->area->update([
        'cached_cogs' => 500.00,
        'cached_customer_price' => 1000.00,
        'cached_tier_prices' => ['good' => ['cogs' => 500, 'price' => 1000]],
    ]);

    $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        coats: 3,
    );

    $area = $this->area->fresh();
    expect($area->cached_cogs)->toBeNull()
        ->and($area->cached_customer_price)->toBeNull()
        ->and($area->cached_tier_prices)->toBeNull();
});

it('returns area tier price after override', function () {
    $result = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        coats: 3,
    );

    expect($result)->toHaveKeys(['area_tier_cogs', 'area_tier_price'])
        ->and($result['area_tier_cogs'])->toBeGreaterThan(0)
        ->and($result['area_tier_price'])->toBeGreaterThan(0);
});

it('produces different COGS for different tiers', function () {
    // Set good = 1 coat, best = 3 coats
    $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'good',
        coats: 1,
    );

    $resultBest = $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        coats: 3,
    );

    $surface = $this->surface->fresh();

    // Verify different tiers stored
    expect($surface->tier_overrides['good']['default_coats'])->toBe(1)
        ->and($surface->tier_overrides['best']['default_coats'])->toBe(3);

    // Labor hours should differ
    $goodHours = $surface->tier_overrides['good']['labor_hours'];
    $bestHours = $surface->tier_overrides['best']['labor_hours'];
    expect($bestHours)->toBeGreaterThan($goodHours);

    // COGS should differ via getLaborHoursForTier
    $goodLaborHours = $surface->getLaborHoursForTier('good');
    $bestLaborHours = $surface->getLaborHoursForTier('best');
    expect($bestLaborHours)->toBeGreaterThan($goodLaborHours);
});

it('preserves existing tier data when updating another tier', function () {
    $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'good',
        coats: 1,
    );

    $this->service->updateSurfaceTierOverrides(
        surfaceId: $this->surface->id,
        tier: 'best',
        coats: 3,
    );

    $surface = $this->surface->fresh();
    expect($surface->tier_overrides)->toHaveKeys(['_base', 'good', 'best'])
        ->and($surface->tier_overrides['good']['default_coats'])->toBe(1)
        ->and($surface->tier_overrides['best']['default_coats'])->toBe(3);
});

it('exposes package_tiers in getEstimateState', function () {
    $state = $this->service->getEstimateState($this->estimate);

    expect($state['areas'][0])->toHaveKey('package_tiers')
        ->and($state['areas'][0]['package_tiers'])->toBeNull();
});
