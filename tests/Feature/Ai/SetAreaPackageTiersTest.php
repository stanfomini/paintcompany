<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
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
    ]);

    CompanySetting::clearInstanceCache();

    $this->area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Accent Wall',
        'scope_type' => 'interior',
    ]);
});

it('sets package tiers on an area', function () {
    $result = $this->service->setAreaPackageTiers(
        areaId: $this->area->id,
        tiers: ['better', 'best'],
    );

    expect($result['area_id'])->toBe($this->area->id)
        ->and($result['package_tiers'])->toBe(['better', 'best'])
        ->and($result['included_in'])->toBe(['better', 'best']);

    $area = $this->area->fresh();
    expect($area->package_tiers)->toBe(['better', 'best'])
        ->and($area->isIncludedInTier('good'))->toBeFalse()
        ->and($area->isIncludedInTier('better'))->toBeTrue()
        ->and($area->isIncludedInTier('best'))->toBeTrue();
});

it('sets null for all tiers when empty array provided', function () {
    // First set some tiers
    $this->service->setAreaPackageTiers($this->area->id, ['best']);

    // Then clear
    $result = $this->service->setAreaPackageTiers($this->area->id, []);

    expect($result['package_tiers'])->toBeNull()
        ->and($result['included_in'])->toBe(['good', 'better', 'best']);

    $area = $this->area->fresh();
    expect($area->package_tiers)->toBeNull()
        ->and($area->isIncludedInTier('good'))->toBeTrue()
        ->and($area->isIncludedInTier('better'))->toBeTrue()
        ->and($area->isIncludedInTier('best'))->toBeTrue();
});

it('sets null when null provided (all tiers)', function () {
    $result = $this->service->setAreaPackageTiers($this->area->id, null);

    expect($result['package_tiers'])->toBeNull()
        ->and($result['included_in'])->toBe(['good', 'better', 'best']);
});

it('filters out invalid tier names', function () {
    $result = $this->service->setAreaPackageTiers(
        areaId: $this->area->id,
        tiers: ['best', 'premium', 'gold'],
    );

    expect($result['package_tiers'])->toBe(['best']);
});

it('clears cached pricing when tiers change', function () {
    $this->area->update([
        'cached_cogs' => 500.00,
        'cached_customer_price' => 1000.00,
        'cached_tier_prices' => ['good' => ['cogs' => 500, 'price' => 1000]],
    ]);

    $this->service->setAreaPackageTiers($this->area->id, ['best']);

    $area = $this->area->fresh();
    expect($area->cached_cogs)->toBeNull()
        ->and($area->cached_customer_price)->toBeNull()
        ->and($area->cached_tier_prices)->toBeNull();
});

it('restricts area to best only', function () {
    $result = $this->service->setAreaPackageTiers($this->area->id, ['best']);

    $area = $this->area->fresh();
    expect($area->isIncludedInTier('good'))->toBeFalse()
        ->and($area->isIncludedInTier('better'))->toBeFalse()
        ->and($area->isIncludedInTier('best'))->toBeTrue();
});
