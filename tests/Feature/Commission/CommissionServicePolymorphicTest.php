<?php

use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Vendor;
use App\Services\CommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applyRulesForCommissionable creates entries for vendor', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create();

    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead',
        'commission_type' => 'flat',
        'amount' => 100,
        'rate' => 0,
        'auto_trigger' => true,
        'is_active' => true,
    ]);

    $service = app(CommissionService::class);
    $entries = $service->applyRulesForCommissionable($vendor, 'lead', $deal);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->commissionable_type)->toBe(Vendor::class)
        ->and($entries[0]->commissionable_id)->toBe($vendor->id)
        ->and((float) $entries[0]->amount)->toBe(100.0)
        ->and($entries[0]->employee_profile_id)->toBeNull();
});

it('applyRulesForCommissionable creates revenue percentage entries', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create();

    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'estimate',
        'commission_type' => 'revenue_percentage',
        'rate' => 0.10,
        'amount' => 0,
        'auto_trigger' => true,
        'is_active' => true,
    ]);

    $service = app(CommissionService::class);
    $entries = $service->applyRulesForCommissionable($vendor, 'estimate', $deal, revenue: 5000);

    expect($entries)->toHaveCount(1)
        ->and((float) $entries[0]->amount)->toBe(500.0)
        ->and((float) $entries[0]->basis_amount)->toBe(5000.0);
});

it('applyRulesForCommissionable skips inactive rules', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create();

    CommissionRule::factory()->inactive()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead',
    ]);

    $service = app(CommissionService::class);
    $entries = $service->applyRulesForCommissionable($vendor, 'lead', $deal);

    expect($entries)->toHaveCount(0);
});

it('applyRulesForCommissionable respects autoOnly filter', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create();

    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead',
        'commission_type' => 'flat',
        'amount' => 50,
        'auto_trigger' => false,
        'is_active' => true,
    ]);

    $service = app(CommissionService::class);
    $autoEntries = $service->applyRulesForCommissionable($vendor, 'lead', $deal, autoOnly: true);
    $allEntries = $service->applyRulesForCommissionable($vendor, 'lead', $deal, autoOnly: false);

    expect($autoEntries)->toHaveCount(0)
        ->and($allEntries)->toHaveCount(1);
});

it('applyRulesForCommissionable passes employeeProfileId through', function () {
    $vendor = Vendor::factory()->create();
    $profile = EmployeeProfile::factory()->create();
    $deal = Deal::factory()->create();

    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead',
        'commission_type' => 'flat',
        'amount' => 75,
        'auto_trigger' => true,
        'is_active' => true,
    ]);

    $service = app(CommissionService::class);
    $entries = $service->applyRulesForCommissionable($vendor, 'lead', $deal, employeeProfileId: $profile->id);

    expect($entries[0]->employee_profile_id)->toBe($profile->id);
});

it('hasDuplicateCommissionForCommissionable detects duplicates', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create();

    CommissionEntry::factory()->forCommissionable($vendor)->create([
        'source_type' => Deal::class,
        'source_id' => $deal->id,
    ]);

    $service = app(CommissionService::class);
    expect($service->hasDuplicateCommissionForCommissionable($vendor, $deal))->toBeTrue();
});

it('hasDuplicateCommissionForCommissionable ignores reversed entries', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create();

    CommissionEntry::factory()->forCommissionable($vendor)->reversed()->create([
        'source_type' => Deal::class,
        'source_id' => $deal->id,
    ]);

    $service = app(CommissionService::class);
    expect($service->hasDuplicateCommissionForCommissionable($vendor, $deal))->toBeFalse();
});

it('evaluateConditions returns true for empty conditions', function () {
    $service = app(CommissionService::class);
    $deal = Deal::factory()->create();

    expect($service->evaluateConditions([], $deal))->toBeTrue();
});

it('evaluateConditions handles equals operator', function () {
    $service = app(CommissionService::class);
    $deal = Deal::factory()->create(['stage' => 'lead']);

    expect($service->evaluateConditions([
        ['field' => 'stage', 'operator' => 'equals', 'value' => 'lead'],
    ], $deal))->toBeTrue();

    expect($service->evaluateConditions([
        ['field' => 'stage', 'operator' => 'equals', 'value' => 'closed'],
    ], $deal))->toBeFalse();
});

it('evaluateConditions handles greater_than operator', function () {
    $service = app(CommissionService::class);
    $deal = Deal::factory()->create(['estimated_value' => 10000]);

    expect($service->evaluateConditions([
        ['field' => 'estimated_value', 'operator' => 'greater_than', 'value' => '5000'],
    ], $deal))->toBeTrue();

    expect($service->evaluateConditions([
        ['field' => 'estimated_value', 'operator' => 'greater_than', 'value' => '15000'],
    ], $deal))->toBeFalse();
});

it('evaluateConditions uses AND logic for multiple conditions', function () {
    $service = app(CommissionService::class);
    $deal = Deal::factory()->create(['stage' => 'lead', 'estimated_value' => 10000]);

    // Both conditions pass
    expect($service->evaluateConditions([
        ['field' => 'stage', 'operator' => 'equals', 'value' => 'lead'],
        ['field' => 'estimated_value', 'operator' => 'greater_than', 'value' => '5000'],
    ], $deal))->toBeTrue();

    // First passes, second fails
    expect($service->evaluateConditions([
        ['field' => 'stage', 'operator' => 'equals', 'value' => 'lead'],
        ['field' => 'estimated_value', 'operator' => 'greater_than', 'value' => '15000'],
    ], $deal))->toBeFalse();
});

it('applyRulesForCommissionable skips rules with failing conditions', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create(['stage' => 'closed']);

    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead',
        'commission_type' => 'flat',
        'amount' => 100,
        'conditions' => [['field' => 'stage', 'operator' => 'equals', 'value' => 'lead']],
        'auto_trigger' => true,
        'is_active' => true,
    ]);

    $service = app(CommissionService::class);
    $entries = $service->applyRulesForCommissionable($vendor, 'lead', $deal);

    expect($entries)->toHaveCount(0);
});

it('applyRulesForCommissionable processes rules with passing conditions', function () {
    $vendor = Vendor::factory()->create();
    $deal = Deal::factory()->create(['stage' => 'lead']);

    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead',
        'commission_type' => 'flat',
        'amount' => 100,
        'conditions' => [['field' => 'stage', 'operator' => 'equals', 'value' => 'lead']],
        'auto_trigger' => true,
        'is_active' => true,
    ]);

    $service = app(CommissionService::class);
    $entries = $service->applyRulesForCommissionable($vendor, 'lead', $deal);

    expect($entries)->toHaveCount(1)
        ->and((float) $entries[0]->amount)->toBe(100.0);
});
