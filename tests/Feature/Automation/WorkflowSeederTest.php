<?php

use App\Models\Workflow;
use App\Models\WorkflowEdge;
use Database\Seeders\WorkflowSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates four default workflows', function () {
    $this->seed(WorkflowSeeder::class);

    expect(Workflow::count())->toBe(4);
});

it('creates new lead follow-up workflow with correct structure', function () {
    $this->seed(WorkflowSeeder::class);

    $workflow = Workflow::where('name', 'New Lead Follow-Up Sequence')->first();

    expect($workflow)->not->toBeNull();
    expect($workflow->is_active)->toBeTrue();
    expect($workflow->is_system)->toBeTrue();
    expect($workflow->published_at)->not->toBeNull();

    // 9 nodes: 1 trigger, 4 actions, 3 waits, 1 if_else
    expect($workflow->nodes()->count())->toBe(9);

    $trigger = $workflow->nodes()->where('type', 'trigger')->first();
    expect($trigger->node_class)->toContain('DealStageChanged');
    expect($trigger->config['target_stage'])->toBe('lead');

    expect($workflow->nodes()->where('type', 'action')->count())->toBe(4);
    expect($workflow->nodes()->where('type', 'wait')->count())->toBe(3);
    expect($workflow->nodes()->where('type', 'if_else')->count())->toBe(1);

    // Edges: trigger→action, action→wait, wait→ifelse, ifelse→follow-up(yes branch),
    // follow-up chain (4 edges) = 8 edges total
    expect($workflow->edges()->count())->toBeGreaterThanOrEqual(7);
});

it('creates estimate sent follow-up workflow with goal node', function () {
    $this->seed(WorkflowSeeder::class);

    $workflow = Workflow::where('name', 'Estimate Sent Follow-Up')->first();

    expect($workflow)->not->toBeNull();

    $trigger = $workflow->nodes()->where('type', 'trigger')->first();
    expect($trigger->node_class)->toContain('EstimateSent');

    // Has goal node for estimate acceptance
    $goal = $workflow->nodes()->where('type', 'goal')->first();
    expect($goal)->not->toBeNull();
    expect($goal->config['conditions'])->not->toBeEmpty();
    expect($goal->config['conditions'][0]['class'])->toContain('FieldValue');
});

it('creates job completion follow-up workflow with linear flow', function () {
    $this->seed(WorkflowSeeder::class);

    $workflow = Workflow::where('name', 'Job Completion Follow-Up')->first();

    expect($workflow)->not->toBeNull();

    // Linear: trigger → action → wait → action → wait → action (6 nodes)
    expect($workflow->nodes()->count())->toBe(6);

    $trigger = $workflow->nodes()->where('type', 'trigger')->first();
    expect($trigger->node_class)->toContain('JobCompleted');

    // 3 actions, 2 waits
    expect($workflow->nodes()->where('type', 'action')->count())->toBe(3);
    expect($workflow->nodes()->where('type', 'wait')->count())->toBe(2);

    // 5 edges connecting them linearly
    expect($workflow->edges()->count())->toBe(5);
});

it('creates stale deal alert workflow with cron trigger', function () {
    $this->seed(WorkflowSeeder::class);

    $workflow = Workflow::where('name', 'Stale Deal Alert')->first();

    expect($workflow)->not->toBeNull();

    $trigger = $workflow->nodes()->where('type', 'trigger')->first();
    expect($trigger->node_class)->toContain('ScheduledCron');
    expect($trigger->config['schedule'])->toBe('daily_9am');

    // Has if/else node
    expect($workflow->nodes()->where('type', 'if_else')->count())->toBe(1);

    // Has condition on edge checking HasNoActivityIn
    $conditionEdge = WorkflowEdge::where('workflow_id', $workflow->id)
        ->whereNotNull('condition_config')
        ->first();
    expect($conditionEdge)->not->toBeNull();
    expect($conditionEdge->condition_config['conditions'][0]['class'])->toContain('HasNoActivityIn');
});

it('can run seeder multiple times without constraint errors', function () {
    $this->seed(WorkflowSeeder::class);
    $this->seed(WorkflowSeeder::class);

    expect(Workflow::count())->toBe(8);
});
