<?php

use App\Automation\Conditions\FlexibleFieldCondition;
use App\Models\Customer;
use App\Models\WorkflowRun;

// --- Equals / Not Equals ---

it('evaluates equals with matching string', function () {
    $customer = Customer::factory()->create(['status' => 'active']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ]))->toBeTrue();
});

it('evaluates equals with non-matching string', function () {
    $customer = Customer::factory()->create(['status' => 'inactive']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ]))->toBeFalse();
});

it('evaluates not_equals', function () {
    $customer = Customer::factory()->create(['status' => 'inactive']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'not_equals',
        'value' => 'active',
    ]))->toBeTrue();
});

// --- Contains / Not Contains ---

it('evaluates contains case-insensitively', function () {
    $customer = Customer::factory()->create(['first_name' => 'John']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'first_name',
        'operator' => 'contains',
        'value' => 'john',
    ]))->toBeTrue();
});

it('evaluates not_contains', function () {
    $customer = Customer::factory()->create(['first_name' => 'John']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'first_name',
        'operator' => 'not_contains',
        'value' => 'Jane',
    ]))->toBeTrue();
});

// --- Starts With / Ends With ---

it('evaluates starts_with', function () {
    $customer = Customer::factory()->create(['email' => 'john@example.com']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'email',
        'operator' => 'starts_with',
        'value' => 'john@',
    ]))->toBeTrue();
});

it('evaluates ends_with', function () {
    $customer = Customer::factory()->create(['email' => 'john@example.com']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'email',
        'operator' => 'ends_with',
        'value' => '@example.com',
    ]))->toBeTrue();
});

// --- Numeric Comparisons ---

it('evaluates greater_than with numeric values', function () {
    $customer = Customer::factory()->create();
    // Use the customer id as a numeric field test
    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'context' => ['score' => 85],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'greater_than',
        'value' => '50',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'greater_than',
        'value' => '90',
    ]))->toBeFalse();
});

it('evaluates less_than with numeric values', function () {
    $run = WorkflowRun::factory()->create([
        'context' => ['score' => 30],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'less_than',
        'value' => '50',
    ]))->toBeTrue();
});

it('evaluates greater_or_equal', function () {
    $run = WorkflowRun::factory()->create([
        'context' => ['score' => 50],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'greater_or_equal',
        'value' => '50',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'greater_or_equal',
        'value' => '51',
    ]))->toBeFalse();
});

it('evaluates less_or_equal', function () {
    $run = WorkflowRun::factory()->create([
        'context' => ['score' => 50],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'less_or_equal',
        'value' => '50',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field' => 'context.score',
        'operator' => 'less_or_equal',
        'value' => '49',
    ]))->toBeFalse();
});

// --- In / Not In ---

it('evaluates in with comma-separated list', function () {
    $customer = Customer::factory()->create(['status' => 'active']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'in',
        'value' => 'active, inactive, lead',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'in',
        'value' => 'closed, archived',
    ]))->toBeFalse();
});

it('evaluates not_in', function () {
    $customer = Customer::factory()->create(['status' => 'active']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'not_in',
        'value' => 'closed, archived',
    ]))->toBeTrue();
});

// --- Is Empty / Is Not Empty ---

it('evaluates is_empty for null value', function () {
    $customer = Customer::factory()->create(['notes' => null]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'notes',
        'operator' => 'is_empty',
    ]))->toBeTrue();
});

it('evaluates is_empty for empty string', function () {
    $customer = Customer::factory()->create(['notes' => '']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'notes',
        'operator' => 'is_empty',
    ]))->toBeTrue();
});

it('evaluates is_not_empty', function () {
    $customer = Customer::factory()->create(['notes' => 'Some notes']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'notes',
        'operator' => 'is_not_empty',
    ]))->toBeTrue();
});

// --- Is True / Is False ---

it('evaluates is_true', function () {
    $run = WorkflowRun::factory()->create([
        'context' => ['approved' => true],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.approved',
        'operator' => 'is_true',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field' => 'context.approved',
        'operator' => 'is_false',
    ]))->toBeFalse();
});

it('evaluates is_false', function () {
    $run = WorkflowRun::factory()->create([
        'context' => ['approved' => false],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.approved',
        'operator' => 'is_false',
    ]))->toBeTrue();
});

// --- Context Field Resolution ---

it('resolves context fields with dot notation', function () {
    $run = WorkflowRun::factory()->create([
        'context' => ['assigned_to' => 42],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'context.assigned_to',
        'operator' => 'equals',
        'value' => 42,
    ]))->toBeTrue();
});

// --- Webhook Field Resolution ---

it('resolves webhook payload fields', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'webhook_payload' => [
                'email' => 'test@example.com',
                'nested' => ['key' => 'value'],
            ],
        ],
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'webhook.email',
        'operator' => 'equals',
        'value' => 'test@example.com',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field' => 'webhook.nested.key',
        'operator' => 'equals',
        'value' => 'value',
    ]))->toBeTrue();
});

// --- Subject Attribute Resolution ---

it('resolves subject attributes directly', function () {
    $customer = Customer::factory()->create(['status' => 'active']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ]))->toBeTrue();
});

// --- Edge Cases ---

it('returns false when field path is null', function () {
    $run = WorkflowRun::factory()->create();

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => null,
        'operator' => 'equals',
        'value' => 'test',
    ]))->toBeFalse();
});

it('returns false when subject is null for subject field', function () {
    $run = WorkflowRun::factory()->create([
        'subject_type' => null,
        'subject_id' => null,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'equals',
        'value' => 'active',
    ]))->toBeFalse();
});

it('returns false for non-numeric greater_than comparison', function () {
    $customer = Customer::factory()->create(['first_name' => 'John']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'first_name',
        'operator' => 'greater_than',
        'value' => '10',
    ]))->toBeFalse();
});

it('returns false for unknown operator', function () {
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $condition = new FlexibleFieldCondition;

    expect($condition->evaluate($run, [
        'field' => 'status',
        'operator' => 'unknown_op',
        'value' => 'test',
    ]))->toBeFalse();
});

it('has correct metadata', function () {
    expect(FlexibleFieldCondition::getName())->toBe('Flexible Field Condition')
        ->and(FlexibleFieldCondition::getCategory())->toBe('Field Checks')
        ->and(FlexibleFieldCondition::getDescription())->toBeString()
        ->and(FlexibleFieldCondition::getConfigSchema())->toHaveKeys(['field', 'operator', 'value', 'logic']);
});
