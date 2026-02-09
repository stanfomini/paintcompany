<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Payment;
use App\Models\Substrate;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    CompanySetting::clearInstanceCache();
    $this->settings = CompanySetting::getInstance();
    $this->settings->ensureSystemAllocations();
});

describe('Dynamic Condition Field Values', function () {

    it('payment_methods values match Payment::METHODS', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['payment_methods']['values'])->toBe(Payment::METHODS);
    });

    it('job_stage values match Job::STAGES', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['job_stage']['values'])->toBe(Job::STAGES);
    });

    it('substrate_categories values match Substrate::getCategories()', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['substrate_categories']['values'])->toBe(Substrate::getCategories());
    });

    it('lead_source values match Deal::LEAD_SOURCES', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['lead_source']['values'])->toBe(Deal::LEAD_SOURCES);
    });

    it('customer_source values match Customer::SOURCES', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['customer_source']['values'])->toBe(Customer::SOURCES);
    });

    it('payment_methods does not contain credit_card', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;
        $paymentKeys = array_keys($conditionFields['payment_methods']['values']);

        expect($paymentKeys)->not->toContain('credit_card')
            ->and($paymentKeys)->toContain('stripe')
            ->and($paymentKeys)->toContain('check')
            ->and($paymentKeys)->toContain('cash')
            ->and($paymentKeys)->toContain('ach')
            ->and($paymentKeys)->toContain('other');
    });
});

describe('Create Allocation with Gate Condition', function () {

    it('saves gate condition to conditions JSON', function () {
        CompanyAllocation::query()->where('is_system', false)->delete();

        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Subcontractor Fee')
            ->set('type', 'percent_of_revenue')
            ->set('value', '5')
            ->set('allocation_rules', [
                [
                    'field' => 'has_subcontractor',
                    'operator' => 'equals',
                    'condition_value' => '1',
                    'has_override' => false,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '',
                    'label' => '',
                ],
            ])
            ->call('save')
            ->assertSet('showModal', false);

        $allocation = CompanyAllocation::where('slug', 'subcontractor_fee')->first();

        expect($allocation)->not->toBeNull()
            ->and($allocation->conditions)->toHaveCount(1)
            ->and($allocation->conditions[0]['field'])->toBe('has_subcontractor')
            ->and($allocation->conditions[0]['operator'])->toBe('equals')
            ->and($allocation->conditions[0]['value'])->toBe('1')
            ->and($allocation->value_tiers)->toBeNull();
    });
});

describe('Create Allocation with Value Tier', function () {

    it('saves value tier to value_tiers JSON', function () {
        CompanyAllocation::query()->where('is_system', false)->delete();

        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Payment Processing')
            ->set('type', 'percent_of_revenue')
            ->set('value', '3')
            ->set('allocation_rules', [
                [
                    'field' => 'payment_methods',
                    'operator' => 'equals',
                    'condition_value' => 'stripe',
                    'has_override' => true,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '2.99',
                    'label' => 'Stripe',
                ],
            ])
            ->call('save')
            ->assertSet('showModal', false);

        $allocation = CompanyAllocation::where('slug', 'payment_processing')->first();

        expect($allocation)->not->toBeNull()
            ->and($allocation->value_tiers)->toHaveCount(1)
            ->and($allocation->value_tiers[0]['label'])->toBe('Stripe')
            ->and($allocation->value_tiers[0]['type'])->toBe('percent_of_revenue')
            ->and((float) $allocation->value_tiers[0]['value'])->toBe(2.99)
            ->and($allocation->value_tiers[0]['conditions'][0]['field'])->toBe('payment_methods')
            ->and($allocation->value_tiers[0]['conditions'][0]['value'])->toBe('stripe')
            ->and($allocation->conditions)->toBeNull();
    });
});

describe('Create Allocation with Mixed Rules', function () {

    it('saves both gate conditions and value tiers', function () {
        CompanyAllocation::query()->where('is_system', false)->delete();

        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('name', 'Processing Mixed')
            ->set('type', 'percent_of_revenue')
            ->set('value', '3')
            ->set('allocation_rules', [
                // Value tier: Stripe → 2.99%
                [
                    'field' => 'payment_methods',
                    'operator' => 'equals',
                    'condition_value' => 'stripe',
                    'has_override' => true,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '2.99',
                    'label' => 'Stripe',
                ],
                // Value tier: Check → $50 fixed
                [
                    'field' => 'payment_methods',
                    'operator' => 'equals',
                    'condition_value' => 'check',
                    'has_override' => true,
                    'override_type' => 'fixed_per_job',
                    'override_value' => '50',
                    'label' => 'Check',
                ],
                // Gate condition: job must be > $1000
                [
                    'field' => 'job_price',
                    'operator' => 'greater_than',
                    'condition_value' => '1000',
                    'has_override' => false,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '',
                    'label' => '',
                ],
            ])
            ->call('save')
            ->assertSet('showModal', false);

        $allocation = CompanyAllocation::where('slug', 'processing_mixed')->first();

        expect($allocation)->not->toBeNull()
            // Gate conditions
            ->and($allocation->conditions)->toHaveCount(1)
            ->and($allocation->conditions[0]['field'])->toBe('job_price')
            ->and($allocation->conditions[0]['operator'])->toBe('greater_than')
            ->and($allocation->conditions[0]['value'])->toBe('1000')
            // Value tiers
            ->and($allocation->value_tiers)->toHaveCount(2)
            ->and($allocation->value_tiers[0]['label'])->toBe('Stripe')
            ->and((float) $allocation->value_tiers[0]['value'])->toBe(2.99)
            ->and($allocation->value_tiers[1]['label'])->toBe('Check')
            ->and($allocation->value_tiers[1]['type'])->toBe('fixed_per_job')
            ->and((float) $allocation->value_tiers[1]['value'])->toBe(50.0);
    });
});

describe('Edit Roundtrip', function () {

    it('loads rules correctly when editing an allocation with tiers and conditions', function () {
        CompanyAllocation::query()->where('is_system', false)->delete();

        $allocation = CompanyAllocation::withoutEvents(function () {
            return CompanyAllocation::factory()->withConditions([
                ['field' => 'job_price', 'operator' => 'greater_than', 'value' => '500'],
            ])->withTiers([
                ['label' => 'Stripe', 'type' => 'percent_of_revenue', 'value' => 2.99, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'stripe'],
                ]],
                ['label' => 'Check', 'type' => 'fixed_per_job', 'value' => 50, 'conditions' => [
                    ['field' => 'payment_methods', 'operator' => 'equals', 'value' => 'check'],
                ]],
            ])->create([
                'name' => 'Test Roundtrip',
                'type' => CompanyAllocation::TYPE_PERCENT_OF_REVENUE,
                'value' => 3.00,
                'is_active' => true,
            ]);
        });

        $component = Livewire::test('pages::settings.allocations')
            ->call('openEditModal', $allocation->id);

        $rules = $component->get('allocation_rules');

        // Should have 3 rules: 2 tiers (with override) + 1 gate condition (without override)
        expect($rules)->toHaveCount(3);

        // First two are tiers (with override)
        expect($rules[0]['field'])->toBe('payment_methods')
            ->and($rules[0]['has_override'])->toBeTrue()
            ->and($rules[0]['override_type'])->toBe('percent_of_revenue')
            ->and($rules[0]['override_value'])->toBe('2.99')
            ->and($rules[0]['label'])->toBe('Stripe')
            ->and($rules[0]['condition_value'])->toBe('stripe');

        expect($rules[1]['field'])->toBe('payment_methods')
            ->and($rules[1]['has_override'])->toBeTrue()
            ->and($rules[1]['override_type'])->toBe('fixed_per_job')
            ->and($rules[1]['override_value'])->toBe('50')
            ->and($rules[1]['label'])->toBe('Check')
            ->and($rules[1]['condition_value'])->toBe('check');

        // Third is a gate condition (without override)
        expect($rules[2]['field'])->toBe('job_price')
            ->and($rules[2]['has_override'])->toBeFalse()
            ->and($rules[2]['operator'])->toBe('greater_than')
            ->and($rules[2]['condition_value'])->toBe('500');
    });
});

describe('Field Change Resets', function () {

    it('resets condition_value when field changes', function () {
        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('allocation_rules', [
                [
                    'field' => 'payment_methods',
                    'operator' => 'equals',
                    'condition_value' => 'stripe',
                    'has_override' => false,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '',
                    'label' => '',
                ],
            ])
            ->set('allocation_rules.0.field', 'lead_source')
            ->assertSet('allocation_rules.0.condition_value', '')
            ->assertSet('allocation_rules.0.operator', 'equals');
    });

    it('resets operator when field changes', function () {
        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('allocation_rules', [
                [
                    'field' => 'job_price',
                    'operator' => 'greater_than',
                    'condition_value' => '5000',
                    'has_override' => false,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '',
                    'label' => '',
                ],
            ])
            ->set('allocation_rules.0.field', 'season')
            ->assertSet('allocation_rules.0.operator', 'equals')
            ->assertSet('allocation_rules.0.condition_value', '');
    });

    it('does not reset condition_value when non-field property changes', function () {
        Livewire::test('pages::settings.allocations')
            ->call('openAddModal')
            ->set('allocation_rules', [
                [
                    'field' => 'payment_methods',
                    'operator' => 'equals',
                    'condition_value' => 'stripe',
                    'has_override' => false,
                    'override_type' => 'percent_of_revenue',
                    'override_value' => '',
                    'label' => '',
                ],
            ])
            ->set('allocation_rules.0.operator', 'in')
            ->assertSet('allocation_rules.0.condition_value', 'stripe');
    });
});

describe('Model Constants in Condition Fields', function () {

    it('scope_type values include both option', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['scope_type']['values'])->toHaveKey(Estimate::PROJECT_TYPE_INTERIOR)
            ->and($conditionFields['scope_type']['values'])->toHaveKey(Estimate::PROJECT_TYPE_EXTERIOR)
            ->and($conditionFields['scope_type']['values'])->toHaveKey(Estimate::PROJECT_TYPE_BOTH)
            ->and($conditionFields['scope_type']['values'][Estimate::PROJECT_TYPE_BOTH])->toBe('Both');
    });

    it('estimator_mode uses Estimate model constants', function () {
        $component = Livewire::test('pages::settings.allocations');

        $conditionFields = $component->instance()->conditionFields;

        expect($conditionFields['estimator_mode']['values'])->toHaveKey(Estimate::MODE_SIMPLE)
            ->and($conditionFields['estimator_mode']['values'])->toHaveKey(Estimate::MODE_TEMPLATE)
            ->and($conditionFields['estimator_mode']['values'])->toHaveKey(Estimate::MODE_DETAILED)
            ->and($conditionFields['estimator_mode']['values'][Estimate::MODE_SIMPLE])->toBe('Simple')
            ->and($conditionFields['estimator_mode']['values'][Estimate::MODE_TEMPLATE])->toBe('Template')
            ->and($conditionFields['estimator_mode']['values'][Estimate::MODE_DETAILED])->toBe('Detailed');
    });
});
