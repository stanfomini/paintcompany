<?php

use App\Listeners\AutomationEventListener;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Services\WorkflowEngine;
use Carbon\Carbon;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['type' => 'internal']);
    $this->actingAs($this->user);
});

function buildLinearWorkflow(array $nodes): array
{
    $workflow = Workflow::factory()->published()->create();

    $createdNodes = [];
    foreach ($nodes as $i => $nodeData) {
        $createdNodes[] = WorkflowNode::factory()->create(array_merge([
            'workflow_id' => $workflow->id,
            'position_x' => $i * 100,
            'position_y' => 0,
        ], $nodeData));
    }

    // Connect nodes linearly
    for ($i = 0; $i < count($createdNodes) - 1; $i++) {
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'from_node_id' => $createdNodes[$i]->id,
            'to_node_id' => $createdNodes[$i + 1]->id,
            'sort_order' => 0,
        ]);
    }

    return [$workflow, $createdNodes];
}

it('starts a workflow run when trigger matches', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Follow up', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    expect(WorkflowRun::count())->toBe(1);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed')
        ->and($run->workflow_id)->toBe($workflow->id)
        ->and($run->subject_type)->toBe($deal->getMorphClass())
        ->and($run->subject_id)->toBe($deal->id);
});

it('does not start workflow when trigger does not match', function () {
    $deal = Deal::factory()->create();

    buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Follow up', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'lead']);

    expect(WorkflowRun::count())->toBe(0);
});

it('does not start inactive workflows', function () {
    $deal = Deal::factory()->create();

    $workflow = Workflow::factory()->published()->inactive()->create();
    WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => \App\Automation\Triggers\DealStageChanged::class,
        'config' => ['target_stage' => 'qualified'],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    expect(WorkflowRun::count())->toBe(0);
});

it('does not start unpublished workflows', function () {
    $deal = Deal::factory()->create();

    $workflow = Workflow::factory()->create(['published_at' => null]);
    WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => \App\Automation\Triggers\DealStageChanged::class,
        'config' => ['target_stage' => 'qualified'],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    expect(WorkflowRun::count())->toBe(0);
});

it('executes a linear sequence of action nodes', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Task 1', 'type' => 'call', 'due_in_hours' => 24]],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Task 2', 'type' => 'email', 'due_in_hours' => 48]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');

    $steps = $run->steps()->orderBy('id')->get();
    expect($steps)->toHaveCount(3) // trigger + 2 actions
        ->and($steps[0]->status)->toBe('completed') // trigger
        ->and($steps[1]->status)->toBe('completed') // action 1
        ->and($steps[2]->status)->toBe('completed'); // action 2

    expect(Activity::count())->toBe(2);
});

it('skips disabled nodes and continues', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Disabled', 'type' => 'call'], 'is_enabled' => false],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Active', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');

    $skippedStep = $run->steps->firstWhere('workflow_node_id', $nodes[1]->id);
    expect($skippedStep->status)->toBe('skipped');

    // Only 1 activity created (not the disabled one)
    expect(Activity::count())->toBe(1);
});

it('evaluates if/else branches correctly', function () {
    $deal = Deal::factory()->create(['stage' => 'qualified']);

    $workflow = Workflow::factory()->published()->create();

    $triggerNode = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => \App\Automation\Triggers\DealStageChanged::class,
        'config' => ['target_stage' => 'qualified'],
    ]);

    $ifElseNode = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'if_else',
        'node_class' => 'if_else',
    ]);

    $yesAction = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => ['title' => 'Yes branch', 'type' => 'call', 'due_in_hours' => 24],
    ]);

    $elseAction = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => ['title' => 'Else branch', 'type' => 'email', 'due_in_hours' => 48],
    ]);

    // Trigger -> IF/ELSE
    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $triggerNode->id,
        'to_node_id' => $ifElseNode->id,
        'sort_order' => 0,
    ]);

    // IF/ELSE -> Yes (with condition: deal stage is qualified)
    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $ifElseNode->id,
        'to_node_id' => $yesAction->id,
        'branch' => 'yes',
        'sort_order' => 0,
        'condition_config' => [
            'logic' => 'and',
            'conditions' => [
                ['class' => \App\Automation\Conditions\DealStageIs::class, 'config' => ['stages' => ['qualified']]],
            ],
        ],
    ]);

    // IF/ELSE -> else
    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $ifElseNode->id,
        'to_node_id' => $elseAction->id,
        'branch' => 'else',
        'sort_order' => 1,
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');

    // Should have taken the "yes" branch
    $yesSteps = $run->steps->where('workflow_node_id', $yesAction->id);
    expect($yesSteps)->toHaveCount(1);

    // Should NOT have the "else" branch
    $elseSteps = $run->steps->where('workflow_node_id', $elseAction->id);
    expect($elseSteps)->toHaveCount(0);
});

it('takes else branch when no conditions match', function () {
    $deal = Deal::factory()->create(['stage' => 'lead']);

    $workflow = Workflow::factory()->published()->create();

    $triggerNode = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => \App\Automation\Triggers\DealStageChanged::class,
        'config' => ['target_stage' => 'lead'],
    ]);

    $ifElseNode = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'if_else',
        'node_class' => 'if_else',
    ]);

    $yesAction = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => ['title' => 'Yes branch', 'type' => 'call', 'due_in_hours' => 24],
    ]);

    $elseAction = WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => ['title' => 'Else branch', 'type' => 'email', 'due_in_hours' => 48],
    ]);

    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $triggerNode->id,
        'to_node_id' => $ifElseNode->id,
        'sort_order' => 0,
    ]);

    // Yes branch: requires stage qualified (won't match since deal is 'lead')
    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $ifElseNode->id,
        'to_node_id' => $yesAction->id,
        'branch' => 'yes',
        'sort_order' => 0,
        'condition_config' => [
            'logic' => 'and',
            'conditions' => [
                ['class' => \App\Automation\Conditions\DealStageIs::class, 'config' => ['stages' => ['qualified']]],
            ],
        ],
    ]);

    // Else branch
    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $ifElseNode->id,
        'to_node_id' => $elseAction->id,
        'branch' => 'else',
        'sort_order' => 1,
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'lead']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');

    // Should NOT have the yes branch
    $yesSteps = $run->steps->where('workflow_node_id', $yesAction->id);
    expect($yesSteps)->toHaveCount(0);

    // Should have the else branch
    $elseSteps = $run->steps->where('workflow_node_id', $elseAction->id);
    expect($elseSteps)->toHaveCount(1);
});

it('pauses at wait nodes and sets scheduled_for', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'wait', 'node_class' => \App\Automation\Waits\TimeDelay::class, 'config' => ['hours' => 24]],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'After wait', 'type' => 'call', 'due_in_hours' => 1]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('waiting')
        ->and($run->current_node_id)->toBe($nodes[1]->id);

    $waitStep = $run->steps()->where('status', 'waiting')->first();
    expect($waitStep)->not->toBeNull()
        ->and($waitStep->scheduled_for)->not->toBeNull();
});

it('resumes from wait node when due', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'wait', 'node_class' => \App\Automation\Waits\TimeDelay::class, 'config' => ['hours' => 1]],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'After wait', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('waiting');

    // Travel forward past the wait time
    Carbon::setTestNow(now()->addHours(2));

    $engine->resume($run->fresh());

    $run->refresh();
    expect($run->status)->toBe('completed');

    Carbon::setTestNow();
});

it('processes waiting steps via processWaitingSteps', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'wait', 'node_class' => \App\Automation\Waits\TimeDelay::class, 'config' => ['hours' => 1]],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'After wait', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    expect(WorkflowRun::first()->status)->toBe('waiting');

    // Not yet due
    $processed = $engine->processWaitingSteps();
    expect($processed)->toBe(0);

    // Travel forward past the wait time
    Carbon::setTestNow(now()->addHours(2));

    $processed = $engine->processWaitingSteps();
    expect($processed)->toBe(1);

    expect(WorkflowRun::first()->fresh()->status)->toBe('completed');

    Carbon::setTestNow();
});

it('cancels a running workflow', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'wait', 'node_class' => \App\Automation\Waits\TimeDelay::class, 'config' => ['hours' => 24]],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Follow up', 'type' => 'call', 'due_in_hours' => 1]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('waiting');

    $engine->cancel($run);

    $run->refresh();
    expect($run->status)->toBe('cancelled')
        ->and($run->completed_at)->not->toBeNull();

    $waitingSteps = $run->steps()->where('status', 'waiting')->count();
    expect($waitingSteps)->toBe(0);
});

it('completes at goal node', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'goal', 'node_class' => 'goal', 'config' => []],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');

    $goalStep = $run->steps()->where('workflow_node_id', $nodes[1]->id)->first();
    expect($goalStep->status)->toBe('completed');
});

it('fails gracefully when action class not found', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => 'App\\Automation\\Actions\\NonExistentAction', 'config' => []],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('not found');
});

it('records steps for each executed node', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Test', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->steps)->toHaveCount(2);

    foreach ($run->steps as $step) {
        expect($step->started_at)->not->toBeNull()
            ->and($step->completed_at)->not->toBeNull()
            ->and($step->status)->toBe('completed');
    }
});

it('resolves dynamic values in action configs', function () {
    $deal = Deal::factory()->create(['title' => 'Kitchen Repaint']);

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'Follow up on {{deal.title}}', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');

    // Check the activity was created with resolved title
    $activity = Activity::where('title', 'Follow up on Kitchen Repaint')->first();
    expect($activity)->not->toBeNull();
});

it('completes when workflow has no edges from last node', function () {
    $deal = Deal::factory()->create();

    // Single trigger node with no outgoing edges
    $workflow = Workflow::factory()->published()->create();
    WorkflowNode::factory()->create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => \App\Automation\Triggers\DealStageChanged::class,
        'config' => ['target_stage' => 'qualified'],
    ]);

    $engine = app(WorkflowEngine::class);
    $engine->handleEvent('deal.stage_changed', $deal, ['to_stage' => 'qualified']);

    $run = WorkflowRun::first();
    expect($run->status)->toBe('completed');
});

it('handles automation event via listener', function () {
    $deal = Deal::factory()->create();

    [$workflow, $nodes] = buildLinearWorkflow([
        ['type' => 'trigger', 'node_class' => \App\Automation\Triggers\DealStageChanged::class, 'config' => ['target_stage' => 'qualified']],
        ['type' => 'action', 'node_class' => \App\Automation\Actions\CreateTask::class, 'config' => ['title' => 'From event', 'type' => 'call', 'due_in_hours' => 24]],
    ]);

    $event = new \App\Events\Automation\DealStageChanged($deal, 'lead', 'qualified');
    $listener = app(AutomationEventListener::class);
    $listener->handle($event);

    expect(WorkflowRun::count())->toBe(1);
    expect(WorkflowRun::first()->status)->toBe('completed');
});

it('handles cleanup stale command', function () {
    $run = WorkflowRun::factory()->create([
        'status' => 'running',
        'triggered_at' => now()->subHours(25),
    ]);

    $this->artisan('workflows:cleanup-stale')
        ->assertExitCode(0);

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('24 hours');
});

it('handles process waiting command', function () {
    $this->artisan('workflows:process-waiting')
        ->assertExitCode(0);
});
