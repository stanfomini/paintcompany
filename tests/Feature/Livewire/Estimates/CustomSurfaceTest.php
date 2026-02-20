<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    CompanySetting::query()->delete();
    CompanySetting::clearInstanceCache();
    $this->settings = CompanySetting::getInstance();
});

it('calculates labor_cost and total for custom surfaces', function () {
    $area = EstimateArea::factory()->create(['scope_type' => 'interior']);
    $effectiveRate = $this->settings->getEffectiveHourlyRateForScope('interior');

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 5.0,
        'hourly_labor_rate' => null,
        'material_cost' => 100.0,
        'production_rate_id' => null,
        'production_rate_value' => 0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->labor_hours)->toBe(5.0);
    expect((float) $surface->material_cost)->toBe(100.0);
    expect((float) $surface->hourly_labor_rate)->toBe(round($effectiveRate, 2));
    expect((float) $surface->labor_cost)->toBe(round(5.0 * $effectiveRate, 2));
    expect((float) $surface->total)->toBe(round(5.0 * $effectiveRate + 100.0, 2));
});

it('uses company effective rate when hourly_labor_rate is null', function () {
    $area = EstimateArea::factory()->create(['scope_type' => 'interior']);
    $effectiveRate = $this->settings->getEffectiveHourlyRateForScope('interior');

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 4.0,
        'hourly_labor_rate' => null,
        'material_cost' => 50.0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->hourly_labor_rate)->toBe(round($effectiveRate, 2));
    expect((float) $surface->labor_cost)->toBe(round(4.0 * $effectiveRate, 2));
});

it('uses exterior effective rate for exterior scope', function () {
    $area = EstimateArea::factory()->create(['scope_type' => 'exterior']);
    $effectiveRate = $this->settings->getEffectiveHourlyRateForScope('exterior');

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 3.0,
        'hourly_labor_rate' => null,
        'material_cost' => 40.0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->hourly_labor_rate)->toBe(round($effectiveRate, 2));
    expect((float) $surface->labor_cost)->toBe(round(3.0 * $effectiveRate, 2));
});

it('allows custom hourly_labor_rate override', function () {
    $area = EstimateArea::factory()->create();

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 3.0,
        'hourly_labor_rate' => 75.00,
        'material_cost' => 25.0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->hourly_labor_rate)->toBe(75.0);
    expect((float) $surface->labor_cost)->toBe(225.0); // 3 * 75
    expect((float) $surface->total)->toBe(250.0); // 225 + 25
});

it('calculates total as labor_cost plus material_cost', function () {
    $area = EstimateArea::factory()->create();

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 2.0,
        'hourly_labor_rate' => 50.00,
        'material_cost' => 80.0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->labor_cost)->toBe(100.0); // 2 * 50
    expect((float) $surface->total)->toBe(180.0); // 100 + 80
});

it('handles material-only custom surface (zero labor hours)', function () {
    $area = EstimateArea::factory()->create();

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 0,
        'hourly_labor_rate' => 50.00,
        'material_cost' => 200.0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->labor_cost)->toBe(0.0);
    expect((float) $surface->total)->toBe(200.0);
});

it('handles labor-only custom surface (zero material cost)', function () {
    $area = EstimateArea::factory()->create();

    $surface = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 4.0,
        'hourly_labor_rate' => 60.00,
        'material_cost' => 0,
        'total' => null,
        'labor_cost' => null,
    ]);

    $surface->refresh();

    expect((float) $surface->labor_cost)->toBe(240.0);
    expect((float) $surface->material_cost)->toBe(0.0);
    expect((float) $surface->total)->toBe(240.0);
});

it('flows through getFullCOGS correctly', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 3.0,
        'hourly_labor_rate' => 50.00,
        'material_cost' => 40.0,
        'labor_cost' => 150.0,
        'total' => 190.0,
        'is_included' => true,
    ]);

    // getFullCOGS reads from surfaces.total + adds cleanup/sundries
    $cogs = $area->getFullCOGS();
    expect($cogs)->toBeGreaterThan(190.0); // Should include cleanup + sundries
});

it('flows through getCustomerPrice correctly', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
    ]);

    EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 3.0,
        'hourly_labor_rate' => 50.00,
        'material_cost' => 40.0,
        'labor_cost' => 150.0,
        'total' => 190.0,
        'is_included' => true,
    ]);

    $customerPrice = $area->getCustomerPrice();
    expect($customerPrice)->toBeGreaterThan($area->getFullCOGS());
});

it('handles mixed auto and custom surfaces correctly', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
    ]);

    // Auto surface
    EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => false,
        'labor_hours' => 2.0,
        'labor_cost' => 100.0,
        'material_cost' => 30.0,
        'total' => 130.0,
        'is_included' => true,
    ]);

    // Custom surface
    EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => true,
        'labor_hours' => 1.5,
        'hourly_labor_rate' => 50.00,
        'labor_cost' => 75.0,
        'material_cost' => 20.0,
        'total' => 95.0,
        'is_included' => true,
    ]);

    $cogs = $area->getCOGS();
    expect($cogs)->toBe(225.0); // 130 + 95
});

it('defaults is_custom to false', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)->create();

    expect($surface->is_custom)->toBeFalse();
});

it('uses factory custom state correctly', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)->custom(3.0, 50.0, 60.0)->create();

    expect($surface->is_custom)->toBeTrue();
    expect((float) $surface->labor_hours)->toBe(3.0);
    expect((float) $surface->material_cost)->toBe(50.0);
    expect((float) $surface->hourly_labor_rate)->toBe(60.0);
    expect((float) $surface->labor_cost)->toBe(180.0); // 3 * 60
    expect((float) $surface->total)->toBe(230.0); // 180 + 50
});

// ============================================================
// SALE PRICE & CREW PRICE TESTS
// ============================================================

it('stores sale_price and crew_price on custom surfaces', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0, 50.0)
        ->withCustomPricing(salePrice: 500.0, crewRate: 30.0)
        ->create();

    $surface->refresh();

    expect($surface->is_custom)->toBeTrue();
    expect((float) $surface->sale_price)->toBe(500.0);
    expect((float) $surface->crew_price)->toBe(30.0);
});

it('returns sale_price as effective sale price when set', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0, 50.0)
        ->withCustomPricing(salePrice: 500.0)
        ->create();

    $surface->refresh();

    expect($surface->getEffectiveSalePrice())->toBe(500.0);
});

it('falls back to calculated customer price when sale_price is null', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create(['estimate_id' => $estimate->id]);
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0, 50.0)
        ->create();

    $surface->refresh();

    // Should return the regular getCustomerPrice() (COGS with margin)
    $effectiveSale = $surface->getEffectiveSalePrice();
    expect($effectiveSale)->toBeGreaterThan((float) $surface->total);
    expect($surface->sale_price)->toBeNull();
});

it('returns crew_price as effective crew rate when set', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0, 50.0)
        ->withCustomPricing(crewRate: 35.0)
        ->create();

    $surface->refresh();

    expect($surface->getEffectiveCrewRate())->toBe(35.0);
});

it('falls back to base wage when crew_price is null', function () {
    $area = EstimateArea::factory()->create(['scope_type' => 'interior']);
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0, 50.0)
        ->create();

    $surface->refresh();

    $baseWage = $this->settings->getHourlyWageForScope('interior');
    expect($surface->getEffectiveCrewRate())->toBe($baseWage);
});

it('calculates crew payment correctly', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(4.0, 50.0, 50.0)
        ->withCustomPricing(crewRate: 25.0)
        ->create();

    $surface->refresh();

    // crew_payment = crew_rate * labor_hours = 25 * 4 = 100
    expect($surface->getCrewPayment())->toBe(100.0);
});

it('reports hasCustomPricing correctly', function () {
    $area = EstimateArea::factory()->create();

    // Custom with sale_price
    $s1 = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0)
        ->withCustomPricing(salePrice: 500.0)
        ->create();
    expect($s1->hasCustomPricing())->toBeTrue();

    // Custom without pricing overrides
    $s2 = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0)
        ->create();
    expect($s2->hasCustomPricing())->toBeFalse();

    // Non-custom with sale_price set (shouldn't count)
    $s3 = EstimateAreaSurface::factory()->forArea($area)->create([
        'sale_price' => 500.0,
    ]);
    expect($s3->hasCustomPricing())->toBeFalse();
});

it('uses uniform COGS pricing for areas with custom sale_price surfaces', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    // Auto surface (normal pricing)
    EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => false,
        'labor_hours' => 2.0,
        'labor_cost' => 100.0,
        'material_cost' => 30.0,
        'total' => 130.0,
        'is_included' => true,
    ]);

    // Custom surface with explicit sale_price (ignored in uniform pricing)
    EstimateAreaSurface::factory()->forArea($area)
        ->custom(3.0, 40.0, 50.0)
        ->withCustomPricing(salePrice: 500.0)
        ->create(['is_included' => true]);

    $customerPrice = $area->getCustomerPrice();
    $cogs = $area->getFullCOGS();

    // Uniform COGS → margin: customer price should exceed total COGS
    expect($customerPrice)->toBeGreaterThan($cogs);
    // sale_price is not used directly — price comes from COGS/margin formula
    expect($customerPrice)->not->toBe(500.0);
});

it('getSurfaceCustomerPrices uses proportional allocation for custom surfaces', function () {
    $estimate = Estimate::factory()->create();
    $area = EstimateArea::factory()->create([
        'estimate_id' => $estimate->id,
        'scope_type' => 'interior',
    ]);

    // Auto surface
    $auto = EstimateAreaSurface::factory()->forArea($area)->create([
        'is_custom' => false,
        'labor_hours' => 2.0,
        'labor_cost' => 100.0,
        'material_cost' => 30.0,
        'total' => 130.0,
        'is_included' => true,
    ]);

    // Custom with sale_price (ignored in proportional allocation now)
    $custom = EstimateAreaSurface::factory()->forArea($area)
        ->custom(3.0, 40.0, 50.0)
        ->withCustomPricing(salePrice: 500.0)
        ->create(['is_included' => true]);

    $prices = $area->getSurfaceCustomerPrices();
    $customerPrice = $area->getCustomerPrice();

    // Both surfaces get proportional allocation
    expect($prices[$custom->id])->toBeGreaterThan(0.0);
    expect($prices[$auto->id])->toBeGreaterThan(0.0);
    // Sum of proportional prices should equal area customer price
    expect(round($prices[$custom->id] + $prices[$auto->id], 2))->toBe(round($customerPrice, 2));
});

it('withCustomPricing factory state works', function () {
    $area = EstimateArea::factory()->create();
    $surface = EstimateAreaSurface::factory()->forArea($area)
        ->custom(5.0, 100.0)
        ->withCustomPricing(salePrice: 750.0, crewRate: 40.0)
        ->create();

    expect((float) $surface->sale_price)->toBe(750.0);
    expect((float) $surface->crew_price)->toBe(40.0);
});
