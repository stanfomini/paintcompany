<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;

beforeEach(function () {
    CompanySetting::getInstance();
});

it('returns true for unconditional allocation', function () {
    $allocation = CompanyAllocation::factory()->create();

    expect($allocation->hasConditions())->toBeFalse();
    expect($allocation->evaluateConditions([]))->toBeTrue();
});

it('detects when conditions are present', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
    ])->create();

    expect($allocation->hasConditions())->toBeTrue();
});

it('evaluates scope equals interior', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'interior'],
    ])->create();

    expect($allocation->evaluateConditions(['has_scope' => ['interior']]))->toBeTrue();
    expect($allocation->evaluateConditions(['has_scope' => ['exterior']]))->toBeFalse();
    expect($allocation->evaluateConditions(['has_scope' => ['interior', 'exterior']]))->toBeTrue();
});

it('evaluates scope equals exterior', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
    ])->create();

    expect($allocation->evaluateConditions(['has_scope' => ['exterior']]))->toBeTrue();
    expect($allocation->evaluateConditions(['has_scope' => ['interior']]))->toBeFalse();
});

it('evaluates scope equals both', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'both'],
    ])->create();

    expect($allocation->evaluateConditions(['has_scope' => ['interior', 'exterior']]))->toBeTrue();
    expect($allocation->evaluateConditions(['has_scope' => ['interior']]))->toBeFalse();
    expect($allocation->evaluateConditions(['has_scope' => ['exterior']]))->toBeFalse();
});

it('evaluates scope not_equals', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'not_equals', 'value' => 'exterior'],
    ])->create();

    expect($allocation->evaluateConditions(['has_scope' => ['interior']]))->toBeTrue();
    expect($allocation->evaluateConditions(['has_scope' => ['exterior']]))->toBeFalse();
});

it('evaluates payment_methods equals', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'credit_card'],
    ])->create();

    expect($allocation->evaluateConditions(['payment_methods' => ['credit_card', 'check']]))->toBeTrue();
    expect($allocation->evaluateConditions(['payment_methods' => ['check', 'cash']]))->toBeFalse();
});

it('evaluates payment_methods in', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'payment_methods', 'operator' => 'in', 'value' => ['credit_card', 'financing']],
    ])->create();

    expect($allocation->evaluateConditions(['payment_methods' => ['financing']]))->toBeTrue();
    expect($allocation->evaluateConditions(['payment_methods' => ['cash']]))->toBeFalse();
});

it('evaluates generic equals operator', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'job_type', 'operator' => 'equals', 'value' => 'residential'],
    ])->create();

    expect($allocation->evaluateConditions(['job_type' => 'residential']))->toBeTrue();
    expect($allocation->evaluateConditions(['job_type' => 'commercial']))->toBeFalse();
});

it('evaluates not_equals operator', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'job_type', 'operator' => 'not_equals', 'value' => 'commercial'],
    ])->create();

    expect($allocation->evaluateConditions(['job_type' => 'residential']))->toBeTrue();
    expect($allocation->evaluateConditions(['job_type' => 'commercial']))->toBeFalse();
});

it('evaluates greater_than operator', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'total_revenue', 'operator' => 'greater_than', 'value' => 5000],
    ])->create();

    expect($allocation->evaluateConditions(['total_revenue' => 6000]))->toBeTrue();
    expect($allocation->evaluateConditions(['total_revenue' => 3000]))->toBeFalse();
    expect($allocation->evaluateConditions(['total_revenue' => 5000]))->toBeFalse();
});

it('evaluates less_than operator', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'total_revenue', 'operator' => 'less_than', 'value' => 5000],
    ])->create();

    expect($allocation->evaluateConditions(['total_revenue' => 3000]))->toBeTrue();
    expect($allocation->evaluateConditions(['total_revenue' => 7000]))->toBeFalse();
});

it('evaluates in operator generically', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'region', 'operator' => 'in', 'value' => ['north', 'south']],
    ])->create();

    expect($allocation->evaluateConditions(['region' => 'north']))->toBeTrue();
    expect($allocation->evaluateConditions(['region' => 'west']))->toBeFalse();
});

it('uses AND logic by default with multiple conditions', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
        ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'credit_card'],
    ])->create();

    // Both pass
    expect($allocation->evaluateConditions([
        'has_scope' => ['exterior'],
        'payment_methods' => ['credit_card'],
    ]))->toBeTrue();

    // Only one passes
    expect($allocation->evaluateConditions([
        'has_scope' => ['interior'],
        'payment_methods' => ['credit_card'],
    ]))->toBeFalse();
});

it('uses OR logic when conditions_logic is any', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
        ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'credit_card'],
    ], 'any')->create();

    // Only one passes - should still return true
    expect($allocation->evaluateConditions([
        'has_scope' => ['interior'],
        'payment_methods' => ['credit_card'],
    ]))->toBeTrue();

    // Neither passes
    expect($allocation->evaluateConditions([
        'has_scope' => ['interior'],
        'payment_methods' => ['cash'],
    ]))->toBeFalse();
});

it('returns empty string for conditions summary when no conditions', function () {
    $allocation = CompanyAllocation::factory()->create();

    expect($allocation->getConditionsSummary())->toBe('');
});

it('generates conditions summary with AND logic', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
        ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'credit_card'],
    ])->create();

    $summary = $allocation->getConditionsSummary();

    expect($summary)->toContain('Applies when:');
    expect($summary)->toContain('has scope is exterior');
    expect($summary)->toContain(' AND ');
    expect($summary)->toContain('payment methods is credit_card');
});

it('generates conditions summary with OR logic', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'interior'],
        ['field' => 'has_scope', 'operator' => 'equals', 'value' => 'exterior'],
    ], 'any')->create();

    $summary = $allocation->getConditionsSummary();

    expect($summary)->toContain(' OR ');
});

it('handles null conditions gracefully', function () {
    $allocation = CompanyAllocation::factory()->create(['conditions' => null]);

    expect($allocation->hasConditions())->toBeFalse();
    expect($allocation->evaluateConditions(['anything' => 'value']))->toBeTrue();
    expect($allocation->getConditionsSummary())->toBe('');
});

it('handles empty conditions array gracefully', function () {
    $allocation = CompanyAllocation::factory()->create(['conditions' => []]);

    expect($allocation->hasConditions())->toBeFalse();
    expect($allocation->evaluateConditions(['anything' => 'value']))->toBeTrue();
});

it('skips conditions with missing field', function () {
    $allocation = CompanyAllocation::factory()->withConditions([
        ['operator' => 'equals', 'value' => 'test'],
    ])->create();

    // Condition skipped, so result is true (no valid conditions to fail)
    expect($allocation->evaluateConditions([]))->toBeTrue();
});
