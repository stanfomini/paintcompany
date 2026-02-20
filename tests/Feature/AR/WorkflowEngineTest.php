<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Job;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAction;
use App\Models\WorkflowLog;
use App\Models\WorkflowTrigger;
use App\Services\WorkflowService;

function createWorkflowWithTriggerAndAction(
    array $triggerAttrs = [],
    array $actionAttrs = [],
    array $workflowAttrs = [],
): Workflow {
    $workflow = Workflow::factory()->create($workflowAttrs);

    WorkflowTrigger::factory()->create(array_merge(
        ['workflow_id' => $workflow->id],
        $triggerAttrs,
    ));

    WorkflowAction::factory()->create(array_merge(
        ['workflow_id' => $workflow->id],
        $actionAttrs,
    ));

    return $workflow->fresh(['triggers', 'actions']);
}

// =========================================================================
// Trigger Matching
// =========================================================================

it('evaluates trigger correctly on model event', function () {
    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: [
            'trigger_model' => 'deal',
            'trigger_event' => 'status_changed',
            'trigger_conditions' => [
                'logic' => 'all',
                'conditions' => [
                    ['field' => 'stage', 'operator' => 'equals', 'value' => 'quoted'],
                ],
            ],
        ],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Workflow ran'],
        ],
    );

    $deal = Deal::factory()->create(['stage' => 'quoted']);

    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'status_changed', ['stage']);

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(WorkflowLog::STATUS_SUCCESS);
    expect($deal->fresh()->description)->toBe('Workflow ran');
});

// =========================================================================
// Condition Operators
// =========================================================================

it('evaluates equals condition operator', function () {
    $service = app(WorkflowService::class);
    $deal = Deal::factory()->create(['stage' => 'quoted']);

    $result = $service->evaluateConditions([
        'logic' => 'all',
        'conditions' => [
            ['field' => 'stage', 'operator' => 'equals', 'value' => 'quoted'],
        ],
    ], $deal);

    expect($result)->toBeTrue();
});

it('evaluates changed_to condition operator', function () {
    $service = app(WorkflowService::class);
    $deal = Deal::factory()->create(['stage' => 'lead']);

    // Simulate a change
    $deal->stage = 'quoted';
    $deal->save();

    $result = $service->evaluateConditions([
        'logic' => 'all',
        'conditions' => [
            ['field' => 'stage', 'operator' => 'changed_to', 'value' => 'quoted'],
        ],
    ], $deal, ['stage']);

    expect($result)->toBeTrue();
});

it('evaluates is_not_null condition operator', function () {
    $service = app(WorkflowService::class);

    $leadGen = User::factory()->internal()->create();
    $deal = Deal::factory()->withLeadGenerator($leadGen)->create();

    $result = $service->evaluateConditions([
        'logic' => 'all',
        'conditions' => [
            ['field' => 'lead_generator_id', 'operator' => 'is_not_null'],
        ],
    ], $deal);

    expect($result)->toBeTrue();
});

it('handles multi-condition AND grouping (logic=all)', function () {
    $service = app(WorkflowService::class);

    $leadGen = User::factory()->internal()->create();
    $deal = Deal::factory()->withLeadGenerator($leadGen)->create(['stage' => 'quoted']);

    $result = $service->evaluateConditions([
        'logic' => 'all',
        'conditions' => [
            ['field' => 'stage', 'operator' => 'equals', 'value' => 'quoted'],
            ['field' => 'lead_generator_id', 'operator' => 'is_not_null'],
        ],
    ], $deal);

    expect($result)->toBeTrue();

    // Failing AND: one condition false
    $dealNoLead = Deal::factory()->create(['stage' => 'quoted']);

    $resultFail = $service->evaluateConditions([
        'logic' => 'all',
        'conditions' => [
            ['field' => 'stage', 'operator' => 'equals', 'value' => 'quoted'],
            ['field' => 'lead_generator_id', 'operator' => 'is_not_null'],
        ],
    ], $dealNoLead);

    expect($resultFail)->toBeFalse();
});

it('handles multi-condition OR grouping (logic=any)', function () {
    $service = app(WorkflowService::class);

    // Only the stage condition should pass
    $deal = Deal::factory()->create(['stage' => 'quoted']);

    $result = $service->evaluateConditions([
        'logic' => 'any',
        'conditions' => [
            ['field' => 'stage', 'operator' => 'equals', 'value' => 'quoted'],
            ['field' => 'lead_generator_id', 'operator' => 'is_not_null'],
        ],
    ], $deal);

    expect($result)->toBeTrue();

    // Neither condition passes
    $dealLead = Deal::factory()->create(['stage' => 'lead']);

    $resultFail = $service->evaluateConditions([
        'logic' => 'any',
        'conditions' => [
            ['field' => 'stage', 'operator' => 'equals', 'value' => 'quoted'],
            ['field' => 'lead_generator_id', 'operator' => 'is_not_null'],
        ],
    ], $dealLead);

    expect($resultFail)->toBeFalse();
});

// =========================================================================
// User Scope Filtering
// =========================================================================

it('filters by specific_user scope', function () {
    $targetUser = User::factory()->internal()->create();
    $otherUser = User::factory()->internal()->create();

    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Scoped'],
        ],
        workflowAttrs: [
            'user_scope_type' => Workflow::USER_SCOPE_SPECIFIC_USER,
            'user_scope_value' => (string) $targetUser->id,
        ],
    );

    $service = app(WorkflowService::class);

    // Deal assigned to the target user — should match
    $matchingDeal = Deal::factory()->assignedTo($targetUser)->create();
    $results = $service->evaluate('deal', $matchingDeal, 'created');
    expect($results)->toHaveCount(1);

    // Deal assigned to another user — should NOT match
    $nonMatchingDeal = Deal::factory()->assignedTo($otherUser)->create();
    $results2 = $service->evaluate('deal', $nonMatchingDeal, 'created');
    expect($results2)->toHaveCount(0);
});

it('filters by role scope', function () {
    // Create the role first since it may not exist in test DB
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'estimator', 'guard_name' => 'web']);

    $user = User::factory()->internal()->create();
    $user->assignRole('estimator');

    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Role scoped'],
        ],
        workflowAttrs: [
            'user_scope_type' => Workflow::USER_SCOPE_ROLE,
            'user_scope_value' => 'estimator',
        ],
    );

    $deal = Deal::factory()->assignedTo($user)->create();
    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'created');

    expect($results)->toHaveCount(1);
});

it('filters by user_type scope', function () {
    $internalUser = User::factory()->internal()->create();

    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Type scoped'],
        ],
        workflowAttrs: [
            'user_scope_type' => Workflow::USER_SCOPE_USER_TYPE,
            'user_scope_value' => 'internal',
        ],
    );

    $deal = Deal::factory()->assignedTo($internalUser)->create();
    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'created');

    expect($results)->toHaveCount(1);
});

// =========================================================================
// Service Category Scope
// =========================================================================

it('filters by service category scope', function () {
    $painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'is_active' => true, 'sort_order' => 1]
    );
    $cleaning = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'is_active' => true, 'sort_order' => 2]
    );

    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'job', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Category scoped'],
        ],
        workflowAttrs: ['service_category_id' => $painting->id],
    );

    // Job with a painting crew — should match
    $crew = User::factory()->crew()->create();
    $job = Job::factory()->create();
    $job->crews()->attach($crew->id, ['status' => 'accepted', 'service_category_id' => $painting->id]);

    $service = app(WorkflowService::class);
    $results = $service->evaluate('job', $job, 'created');
    expect($results)->toHaveCount(1);

    // Job with only cleaning crew — should NOT match
    $job2 = Job::factory()->create();
    $crewCleaning = User::factory()->crew()->create();
    $job2->crews()->attach($crewCleaning->id, ['status' => 'accepted', 'service_category_id' => $cleaning->id]);

    $results2 = $service->evaluate('job', $job2, 'created');
    expect($results2)->toHaveCount(0);
});

// =========================================================================
// Action Execution
// =========================================================================

it('executes create_commission action via CommissionService', function () {
    $user = User::factory()->internal()->create();
    $employee = EmployeeProfile::factory()
        ->withLeadCommission('flat', 50)
        ->create(['user_id' => $user->id]);

    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_CREATE_COMMISSION,
            'target_user_field' => 'assigned_to',
        ],
    );

    $deal = Deal::factory()->assignedTo($user)->create();
    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'created');

    expect($results)->toHaveCount(1);
    expect($results[0]->status)->toBe(WorkflowLog::STATUS_SUCCESS);
    expect($results[0]->actions_executed[0]['action'])->toBe('create_commission');
});

it('executes create_flat_rate and creates a CommissionEntry with fixed type', function () {
    $user = User::factory()->internal()->create();
    EmployeeProfile::factory()->create(['user_id' => $user->id]);

    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_CREATE_FLAT_RATE,
            'action_config' => ['amount' => 75.00],
            'target_user_field' => 'assigned_to',
        ],
    );

    $deal = Deal::factory()->assignedTo($user)->create();
    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'created');

    expect($results)->toHaveCount(1);

    $entry = CommissionEntry::where('source_type', Deal::class)
        ->where('source_id', $deal->id)
        ->where('commission_type', 'flat')
        ->first();

    expect($entry)->not->toBeNull();
    expect((float) $entry->amount)->toBe(75.00);
});

// =========================================================================
// Manual Execution
// =========================================================================

it('creates a log with is_manual=true when run manually', function () {
    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'status_changed'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Manual run'],
        ],
    );

    $deal = Deal::factory()->create();

    $service = app(WorkflowService::class);
    $log = $service->runManually($workflow, $deal);

    expect($log->is_manual)->toBeTrue();
    expect($log->status)->toBe(WorkflowLog::STATUS_SUCCESS);
    expect($deal->fresh()->description)->toBe('Manual run');
});

// =========================================================================
// Observer Integration
// =========================================================================

it('observer fires workflows on Deal stage change', function () {
    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: [
            'trigger_model' => 'deal',
            'trigger_event' => 'status_changed',
            'trigger_conditions' => [
                'logic' => 'all',
                'conditions' => [
                    ['field' => 'stage', 'operator' => 'changed_to', 'value' => 'quoted'],
                ],
            ],
        ],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Observer triggered'],
        ],
    );

    // Create a deal at lead stage (will trigger 'created' event which won't match)
    $deal = Deal::factory()->create(['stage' => 'lead', 'description' => null]);

    // Update stage to quoted — the observer should fire 'status_changed'
    $deal->stage = 'quoted';
    $deal->save();

    // Verify the log was created
    $log = WorkflowLog::where('workflow_id', $workflow->id)
        ->where('trigger_model_type', Deal::class)
        ->where('trigger_model_id', $deal->id)
        ->first();

    expect($log)->not->toBeNull();
    expect($log->status)->toBe(WorkflowLog::STATUS_SUCCESS);
    expect($deal->fresh()->description)->toBe('Observer triggered');
});

// =========================================================================
// Logging
// =========================================================================

it('captures action results and model snapshot in workflow log', function () {
    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Logged'],
        ],
    );

    $deal = Deal::factory()->create(['title' => 'Test Deal Log']);

    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'created');

    expect($results)->toHaveCount(1);

    $log = $results[0];
    expect($log->trigger_snapshot)->toBeArray();
    expect($log->trigger_snapshot['title'])->toBe('Test Deal Log');
    expect($log->actions_executed)->toBeArray();
    expect($log->actions_executed[0]['action'])->toBe('update_field');
    expect($log->actions_executed[0]['status'])->toBe('executed');
    expect($log->actions_executed[0]['field'])->toBe('description');
});

// =========================================================================
// Inactive Workflow
// =========================================================================

it('does not execute inactive workflows', function () {
    $workflow = createWorkflowWithTriggerAndAction(
        triggerAttrs: ['trigger_model' => 'deal', 'trigger_event' => 'created'],
        actionAttrs: [
            'action_type' => WorkflowAction::ACTION_UPDATE_FIELD,
            'action_config' => ['field' => 'description', 'value' => 'Should not run'],
        ],
        workflowAttrs: ['is_active' => false],
    );

    $deal = Deal::factory()->create();
    $service = app(WorkflowService::class);
    $results = $service->evaluate('deal', $deal, 'created');

    expect($results)->toHaveCount(0);
    expect($deal->fresh()->description)->not->toBe('Should not run');
});
