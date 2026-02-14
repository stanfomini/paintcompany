<?php

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a workflow with factory', function () {
    $workflow = Workflow::factory()->create(['name' => 'Test Workflow']);

    expect($workflow)
        ->name->toBe('Test Workflow')
        ->is_active->toBeTrue()
        ->is_system->toBeFalse()
        ->version->toBe(1);
});

it('can create a published workflow', function () {
    $workflow = Workflow::factory()->published()->create();

    expect($workflow->published_at)->not->toBeNull();
});

it('can create an inactive workflow', function () {
    $workflow = Workflow::factory()->inactive()->create();

    expect($workflow->is_active)->toBeFalse();
});

it('has creator relationship', function () {
    $user = User::factory()->internal()->create();
    $workflow = Workflow::factory()->create(['created_by' => $user->id]);

    expect($workflow->creator->id)->toBe($user->id);
});

it('has nodes relationship', function () {
    $workflow = Workflow::factory()->create();
    WorkflowNode::factory()->count(3)->create(['workflow_id' => $workflow->id]);

    expect($workflow->nodes)->toHaveCount(3);
});

it('has edges relationship', function () {
    $workflow = Workflow::factory()->create();
    $nodeA = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);
    $nodeB = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);

    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $nodeA->id,
        'to_node_id' => $nodeB->id,
    ]);

    expect($workflow->edges)->toHaveCount(1);
});

it('has runs relationship', function () {
    $workflow = Workflow::factory()->create();
    WorkflowRun::factory()->count(2)->create(['workflow_id' => $workflow->id]);

    expect($workflow->runs)->toHaveCount(2);
});

it('scopes active workflows', function () {
    Workflow::factory()->create(['is_active' => true]);
    Workflow::factory()->create(['is_active' => false]);

    expect(Workflow::active()->count())->toBe(1);
});

it('scopes published workflows', function () {
    Workflow::factory()->published()->create();
    Workflow::factory()->create(['published_at' => null]);

    expect(Workflow::published()->count())->toBe(1);
});

it('soft deletes workflows', function () {
    $workflow = Workflow::factory()->create();
    $workflow->delete();

    expect(Workflow::count())->toBe(0);
    expect(Workflow::withTrashed()->count())->toBe(1);
});

it('creates workflow nodes with correct casts', function () {
    $node = WorkflowNode::factory()->create([
        'config' => ['key' => 'value'],
        'position_x' => 100.5,
        'position_y' => 200.3,
        'is_enabled' => true,
    ]);

    $node->refresh();

    expect($node->config)->toBe(['key' => 'value'])
        ->and($node->position_x)->toBeFloat()
        ->and($node->is_enabled)->toBeTrue();
});

it('node has outgoing and incoming edges', function () {
    $workflow = Workflow::factory()->create();
    $nodeA = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);
    $nodeB = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);
    $nodeC = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);

    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $nodeA->id,
        'to_node_id' => $nodeB->id,
    ]);
    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $nodeA->id,
        'to_node_id' => $nodeC->id,
    ]);

    expect($nodeA->outgoingEdges)->toHaveCount(2);
    expect($nodeB->incomingEdges)->toHaveCount(1);
});

it('edge has from and to node relationships', function () {
    $workflow = Workflow::factory()->create();
    $nodeA = WorkflowNode::factory()->create(['workflow_id' => $workflow->id, 'label' => 'Start']);
    $nodeB = WorkflowNode::factory()->create(['workflow_id' => $workflow->id, 'label' => 'End']);

    $edge = WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $nodeA->id,
        'to_node_id' => $nodeB->id,
        'branch' => 'yes',
    ]);

    expect($edge->fromNode->label)->toBe('Start');
    expect($edge->toNode->label)->toBe('End');
    expect($edge->branch)->toBe('yes');
});

it('workflow run has correct relationships', function () {
    $workflow = Workflow::factory()->create();
    $node = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);
    $user = User::factory()->internal()->create();

    $run = WorkflowRun::factory()->create([
        'workflow_id' => $workflow->id,
        'current_node_id' => $node->id,
        'triggered_by' => $user->id,
    ]);

    expect($run->workflow->id)->toBe($workflow->id);
    expect($run->currentNode->id)->toBe($node->id);
    expect($run->triggeredBy->id)->toBe($user->id);
});

it('workflow run has status scopes', function () {
    WorkflowRun::factory()->create(['status' => 'running']);
    WorkflowRun::factory()->create(['status' => 'waiting']);
    WorkflowRun::factory()->completed()->create();

    expect(WorkflowRun::running()->count())->toBe(1);
    expect(WorkflowRun::waiting()->count())->toBe(1);
    expect(WorkflowRun::completed()->count())->toBe(1);
});

it('workflow run supports polymorphic subject', function () {
    $user = User::factory()->internal()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    expect($run->subject)->toBeInstanceOf(User::class);
    expect($run->subject->id)->toBe($user->id);
});

it('workflow run step has correct relationships', function () {
    $workflow = Workflow::factory()->create();
    $node = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);
    $run = WorkflowRun::factory()->create(['workflow_id' => $workflow->id]);

    $step = WorkflowRunStep::factory()->create([
        'workflow_run_id' => $run->id,
        'workflow_node_id' => $node->id,
    ]);

    expect($step->run->id)->toBe($run->id);
    expect($step->node->id)->toBe($node->id);
});

it('workflow run step has status scopes', function () {
    WorkflowRunStep::factory()->create(['status' => 'pending']);
    WorkflowRunStep::factory()->waiting()->create();
    WorkflowRunStep::factory()->create([
        'status' => 'waiting',
        'scheduled_for' => now()->subHour(),
    ]);

    expect(WorkflowRunStep::pending()->count())->toBe(1);
    expect(WorkflowRunStep::waiting()->count())->toBe(2);
    expect(WorkflowRunStep::due()->count())->toBe(1);
});

it('cascade deletes nodes when workflow is force deleted', function () {
    $workflow = Workflow::factory()->create();
    WorkflowNode::factory()->count(3)->create(['workflow_id' => $workflow->id]);

    expect(WorkflowNode::count())->toBe(3);

    $workflow->forceDelete();

    expect(WorkflowNode::count())->toBe(0);
});

it('cascade deletes edges when workflow is force deleted', function () {
    $workflow = Workflow::factory()->create();
    $nodeA = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);
    $nodeB = WorkflowNode::factory()->create(['workflow_id' => $workflow->id]);

    WorkflowEdge::factory()->create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $nodeA->id,
        'to_node_id' => $nodeB->id,
    ]);

    expect(WorkflowEdge::count())->toBe(1);

    $workflow->forceDelete();

    expect(WorkflowEdge::count())->toBe(0);
});

it('cascade deletes run steps when run is deleted', function () {
    $run = WorkflowRun::factory()->create();
    WorkflowRunStep::factory()->count(3)->create(['workflow_run_id' => $run->id]);

    expect(WorkflowRunStep::count())->toBe(3);

    $run->delete();

    expect(WorkflowRunStep::count())->toBe(0);
});
