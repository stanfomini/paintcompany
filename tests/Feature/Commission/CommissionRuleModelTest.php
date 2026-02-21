<?php

use App\Models\CommissionRule;
use App\Models\EmployeeProfile;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a commission rule with factory defaults', function () {
    $rule = CommissionRule::factory()->create();

    expect($rule->source)->toBe('estimate')
        ->and($rule->commission_type)->toBe('revenue_percentage')
        ->and((float) $rule->rate)->toBe(0.05)
        ->and($rule->auto_trigger)->toBeTrue()
        ->and($rule->is_active)->toBeTrue();
});

it('resolves commissionable morphTo relationship', function () {
    $profile = EmployeeProfile::factory()->create();
    $rule = CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
    ]);

    expect($rule->commissionable)->toBeInstanceOf(EmployeeProfile::class)
        ->and($rule->commissionable->id)->toBe($profile->id);
});

it('creates flat commission rule', function () {
    $rule = CommissionRule::factory()->flat(75)->create();

    expect($rule->commission_type)->toBe('flat')
        ->and((float) $rule->amount)->toBe(75.0)
        ->and((float) $rule->rate)->toBe(0.0);
});

it('creates inactive rule', function () {
    $rule = CommissionRule::factory()->inactive()->create();

    expect($rule->is_active)->toBeFalse();
});

it('creates manual-only rule', function () {
    $rule = CommissionRule::factory()->manualOnly()->create();

    expect($rule->auto_trigger)->toBeFalse();
});

it('scopes active rules', function () {
    CommissionRule::factory()->create(['is_active' => true]);
    CommissionRule::factory()->inactive()->create();

    expect(CommissionRule::active()->count())->toBe(1);
});

it('scopes rules by source', function () {
    CommissionRule::factory()->forSource('lead')->create();
    CommissionRule::factory()->forSource('estimate')->create();
    CommissionRule::factory()->forSource('estimate')->create();

    expect(CommissionRule::forSource('estimate')->count())->toBe(2)
        ->and(CommissionRule::forSource('lead')->count())->toBe(1);
});

it('scopes auto trigger rules', function () {
    CommissionRule::factory()->create(['auto_trigger' => true]);
    CommissionRule::factory()->manualOnly()->create();

    expect(CommissionRule::autoTrigger()->count())->toBe(1);
});

it('casts conditions as array', function () {
    $conditions = [['field' => 'deal.value', 'operator' => 'greater_than', 'value' => '5000']];
    $rule = CommissionRule::factory()->create(['conditions' => $conditions]);

    $rule->refresh();
    expect($rule->conditions)->toBeArray()
        ->and($rule->conditions[0]['field'])->toBe('deal.value');
});

// HasCommissionRules trait tests

it('employee profile can have commission rules via trait', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'estimate',
    ]);

    expect($profile->commissionRulesRelation()->count())->toBe(1);
});

it('vendor can have commission rules via trait', function () {
    $vendor = Vendor::factory()->create();
    CommissionRule::factory()->create([
        'commissionable_type' => Vendor::class,
        'commissionable_id' => $vendor->id,
        'source' => 'lead_source',
    ]);

    expect($vendor->commissionRulesRelation()->count())->toBe(1);
});

it('getTableCommissionRulesForSource returns rules for source', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'estimate',
        'commission_type' => 'revenue_percentage',
        'rate' => 0.10,
        'auto_trigger' => true,
    ]);
    CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'lead',
    ]);

    $rules = $profile->getTableCommissionRulesForSource('estimate');
    expect($rules)->toHaveCount(1)
        ->and($rules[0]['source'])->toBe('estimate')
        ->and($rules[0]['type'])->toBe('revenue_percentage')
        ->and($rules[0]['rate'])->toBe(0.10);
});

it('getTableCommissionRulesForSource filters auto_trigger', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'estimate',
        'auto_trigger' => true,
    ]);
    CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'estimate',
        'auto_trigger' => false,
    ]);

    expect($profile->getTableCommissionRulesForSource('estimate', autoOnly: true))->toHaveCount(1)
        ->and($profile->getTableCommissionRulesForSource('estimate', autoOnly: false))->toHaveCount(2);
});

it('getTableCommissionRulesForSource excludes inactive rules', function () {
    $profile = EmployeeProfile::factory()->create();
    CommissionRule::factory()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'estimate',
        'is_active' => true,
    ]);
    CommissionRule::factory()->inactive()->create([
        'commissionable_type' => EmployeeProfile::class,
        'commissionable_id' => $profile->id,
        'source' => 'estimate',
    ]);

    expect($profile->getTableCommissionRulesForSource('estimate'))->toHaveCount(1);
});

it('existing getCommissionRulesForSource on EmployeeProfile still works with JSON', function () {
    $profile = EmployeeProfile::factory()->create([
        'commission_rules' => [
            ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
            ['source' => 'lead', 'type' => 'flat', 'amount' => 50, 'active' => true],
        ],
    ]);

    $rules = $profile->getCommissionRulesForSource('estimate');
    expect($rules)->toHaveCount(1)
        ->and($rules[0]['type'])->toBe('revenue_percentage');
});
