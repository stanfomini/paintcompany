<?php

use App\Automation\Actions\RoundRobinAssign;
use App\Models\Customer;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

it('assigns to specific users in round-robin order', function () {
    $user1 = User::factory()->internal()->create();
    $user2 = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;

    $result1 = $action->execute($run, [
        'group_type' => 'specific_users',
        'user_ids' => "{$user1->id},{$user2->id}",
    ]);

    expect($result1['assigned'])->toBeTrue()
        ->and($result1['assigned_to'])->toBe($user1->id);

    $result2 = $action->execute($run, [
        'group_type' => 'specific_users',
        'user_ids' => "{$user1->id},{$user2->id}",
    ]);

    expect($result2['assigned'])->toBeTrue()
        ->and($result2['assigned_to'])->toBe($user2->id);
});

it('assigns to users by role in round-robin order', function () {
    $agent1 = User::factory()->agent()->create(['is_active' => true]);
    $agent2 = User::factory()->agent()->create(['is_active' => true]);
    // Inactive agent should be excluded
    User::factory()->agent()->create(['is_active' => false]);

    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;

    $result1 = $action->execute($run, [
        'group_type' => 'role',
        'role' => 'agent',
    ]);

    expect($result1['assigned'])->toBeTrue()
        ->and($result1['assigned_to'])->toBeIn([$agent1->id, $agent2->id]);

    $firstAssigned = $result1['assigned_to'];

    $result2 = $action->execute($run, [
        'group_type' => 'role',
        'role' => 'agent',
    ]);

    expect($result2['assigned'])->toBeTrue()
        ->and($result2['assigned_to'])->not->toBe($firstAssigned);
});

it('cycles back to first user after completing rotation', function () {
    $user1 = User::factory()->internal()->create();
    $user2 = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;
    $config = [
        'group_type' => 'specific_users',
        'user_ids' => "{$user1->id},{$user2->id}",
    ];

    $result1 = $action->execute($run, $config);
    $result2 = $action->execute($run, $config);
    $result3 = $action->execute($run, $config);

    expect($result1['assigned_to'])->toBe($user1->id)
        ->and($result2['assigned_to'])->toBe($user2->id)
        ->and($result3['assigned_to'])->toBe($user1->id);
});

it('updates subject assignment field', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create(['assigned_to' => null]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;
    $action->execute($run, [
        'group_type' => 'specific_users',
        'user_ids' => (string) $user->id,
    ]);

    expect($customer->fresh()->assigned_to)->toBe($user->id);
});

it('sets assigned_to in workflow run context', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'context' => [],
    ]);

    $action = new RoundRobinAssign;
    $action->execute($run, [
        'group_type' => 'specific_users',
        'user_ids' => (string) $user->id,
    ]);

    expect($run->fresh()->context['assigned_to'])->toBe($user->id);
});

it('returns error when no users available', function () {
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;
    $result = $action->execute($run, [
        'group_type' => 'specific_users',
        'user_ids' => '',
    ]);

    expect($result['assigned'])->toBeFalse()
        ->and($result['error'])->toBe('No users available for assignment');
});

it('returns error when role has no active users', function () {
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;
    $result = $action->execute($run, [
        'group_type' => 'role',
        'role' => 'agent',
    ]);

    expect($result['assigned'])->toBeFalse()
        ->and($result['error'])->toBe('No users available for assignment');
});

it('returns error when role is not specified for role group type', function () {
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;
    $result = $action->execute($run, [
        'group_type' => 'role',
    ]);

    expect($result['assigned'])->toBeFalse()
        ->and($result['error'])->toBe('No users available for assignment');
});

it('defaults to specific_users when group_type is not set', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new RoundRobinAssign;
    $result = $action->execute($run, [
        'user_ids' => (string) $user->id,
    ]);

    expect($result['assigned'])->toBeTrue()
        ->and($result['assigned_to'])->toBe($user->id);
});

it('has correct config schema with group_type and role fields', function () {
    $schema = RoundRobinAssign::getConfigSchema();

    expect($schema)->toHaveKeys(['group_type', 'user_ids', 'role', 'assignment_field'])
        ->and($schema['group_type']['options'])->toHaveKeys(['specific_users', 'role'])
        ->and($schema['role']['options'])->toHaveKeys(['internal', 'agent']);
});
