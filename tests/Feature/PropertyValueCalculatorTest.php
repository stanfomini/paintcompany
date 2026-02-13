<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\RoiCalculation;
use App\Models\User;
use App\Services\PropertyValueCalculatorService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->service = new PropertyValueCalculatorService;
});

// ---------------------------------------------------------------------------
// Cost Estimation
// ---------------------------------------------------------------------------

it('calculates interior painting cost for a standard home', function () {
    // Ensure company settings exist with known rates
    CompanySetting::getInstance();

    $result = $this->service->estimatePaintingCost(
        sqft: 2000,
        bedrooms: 3,
        scope: 'interior'
    );

    // 3 bedrooms + ceil(3 * 1.5) = 3 + 5 = 8 rooms
    // avg room sqft = 2000 / 8 = 250
    // midpoint = 8 * 250 * 4.50 = 9000
    expect($result['midpoint'])->toBe(9000.0)
        ->and($result['low'])->toBe(round(9000 * 0.85, 2))
        ->and($result['high'])->toBe(round(9000 * 1.15, 2));
});

it('calculates exterior painting cost', function () {
    CompanySetting::getInstance();

    $result = $this->service->estimatePaintingCost(
        sqft: 2000,
        bedrooms: 3,
        scope: 'exterior'
    );

    // Exterior: sqft * rate = 2000 * 3.50 = 7000
    expect($result['midpoint'])->toBe(7000.0)
        ->and($result['low'])->toBe(round(7000 * 0.85, 2))
        ->and($result['high'])->toBe(round(7000 * 1.15, 2));
});

it('calculates both scope costs as sum of interior and exterior', function () {
    CompanySetting::getInstance();

    $interior = $this->service->estimatePaintingCost(2000, 3, 'interior');
    $exterior = $this->service->estimatePaintingCost(2000, 3, 'exterior');
    $both = $this->service->estimatePaintingCost(2000, 3, 'both');

    $expectedMidpoint = $interior['midpoint'] + $exterior['midpoint'];

    expect($both['midpoint'])->toBe(round($expectedMidpoint, 2))
        ->and($both['low'])->toBe(round($expectedMidpoint * 0.85, 2))
        ->and($both['high'])->toBe(round($expectedMidpoint * 1.15, 2));
});

// ---------------------------------------------------------------------------
// Value Increase Estimation
// ---------------------------------------------------------------------------

it('estimates value increase for selling intent with exterior scope', function () {
    $result = $this->service->estimateValueIncrease(
        propertyValue: 500000,
        costLow: 5000,
        costHigh: 7000,
        scope: 'exterior'
    );

    // Exterior: 2-5% of home value
    expect($result['low'])->toBe(round(500000 * 0.02, 2))   // 10,000
        ->and($result['high'])->toBe(round(500000 * 0.05, 2)); // 25,000
});

it('estimates value increase for selling intent with interior scope', function () {
    $result = $this->service->estimateValueIncrease(
        propertyValue: 500000,
        costLow: 5000,
        costHigh: 7000,
        scope: 'interior'
    );

    // Interior: 107% ROI (cost * 1.07)
    expect($result['low'])->toBe(round(5000 * 1.07, 2))   // 5,350
        ->and($result['high'])->toBe(round(7000 * 1.07, 2)); // 7,490
});

it('estimates value increase for buying intent', function () {
    // Buying intent uses the same formula — the value increase is the same
    // regardless of intent. Intent is stored for context, not calculation.
    $result = $this->service->estimateValueIncrease(
        propertyValue: 400000,
        costLow: 4000,
        costHigh: 6000,
        scope: 'both'
    );

    // Both: weighted blend (60% interior, 40% exterior)
    $interiorLow = 4000 * 1.07;
    $interiorHigh = 6000 * 1.07;
    $exteriorLow = 400000 * 0.02;
    $exteriorHigh = 400000 * 0.05;

    $expectedLow = round(($interiorLow * 0.60) + ($exteriorLow * 0.40), 2);
    $expectedHigh = round(($interiorHigh * 0.60) + ($exteriorHigh * 0.40), 2);

    expect($result['low'])->toBe($expectedLow)
        ->and($result['high'])->toBe($expectedHigh);
});

// ---------------------------------------------------------------------------
// ROI Calculation
// ---------------------------------------------------------------------------

it('calculates ROI percentages correctly', function () {
    $result = $this->service->calculateRoi(
        costLow: 5000,
        costHigh: 7000,
        valueIncreaseLow: 10000,
        valueIncreaseHigh: 25000
    );

    // Low ROI = conservative: low value increase / high cost
    // 10000 / 7000 * 100 = 142.86
    expect($result['low'])->toBe(round((10000 / 7000) * 100, 2))
        // High ROI = optimistic: high value increase / low cost
        // 25000 / 5000 * 100 = 500.00
        ->and($result['high'])->toBe(round((25000 / 5000) * 100, 2));
});

// ---------------------------------------------------------------------------
// Persistence
// ---------------------------------------------------------------------------

it('saves calculation to database with public token', function () {
    $agent = User::factory()->agent()->create();
    $deal = Deal::factory()->create(['agent_id' => $agent->id]);

    CompanySetting::getInstance();

    $results = $this->service->calculate(
        propertyValue: 500000,
        sqft: 2000,
        bedrooms: 3,
        scope: 'interior',
        intent: 'selling'
    );

    $calculation = $this->service->saveCalculation($deal, $agent, $results);

    expect($calculation)->toBeInstanceOf(RoiCalculation::class)
        ->and($calculation->exists)->toBeTrue()
        ->and($calculation->deal_id)->toBe($deal->id)
        ->and($calculation->agent_id)->toBe($agent->id)
        ->and((float) $calculation->estimated_cost_low)->toBe($results['cost_low'])
        ->and((float) $calculation->estimated_cost_high)->toBe($results['cost_high'])
        ->and($calculation->public_token)->not->toBeNull()
        ->and($calculation->public_token)->toHaveLength(64)
        ->and($calculation->isPublicTokenValid())->toBeTrue()
        ->and($calculation->calculation_inputs)->toBeArray()
        ->and($calculation->calculation_inputs['scope'])->toBe('interior');
});

// ---------------------------------------------------------------------------
// Company Settings Integration
// ---------------------------------------------------------------------------

it('uses company settings for rate calculations', function () {
    // Create settings with custom rates
    $settings = CompanySetting::getInstance();
    $settings->update([
        'default_interior_sqft_rate' => 6.00,
        'default_exterior_sqft_rate' => 5.00,
    ]);
    CompanySetting::clearInstanceCache();

    $result = $this->service->estimatePaintingCost(
        sqft: 1500,
        bedrooms: 2,
        scope: 'exterior'
    );

    // Exterior: sqft * rate = 1500 * 5.00 = 7500
    expect($result['midpoint'])->toBe(7500.0);

    $interiorResult = $this->service->estimatePaintingCost(
        sqft: 1500,
        bedrooms: 2,
        scope: 'interior'
    );

    // 2 bedrooms + ceil(2 * 1.5) = 2 + 3 = 5 rooms
    // avg room sqft = 1500 / 5 = 300
    // midpoint = 5 * 300 * 6.00 = 9000
    expect($interiorResult['midpoint'])->toBe(9000.0);
});
