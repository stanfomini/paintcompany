<?php

use App\Automation\Actions\SendEmail;
use App\Automation\Conditions\FieldValue;
use App\Automation\Registry;
use App\Automation\Triggers\ContactCreated;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Services\WorkflowAiService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->actingAs($this->user);
    $this->service = app(WorkflowAiService::class);
});

// === addNode ===

it('adds a trigger node to an empty workflow', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $result = $this->service->addNode(
        workflow: $workflow,
        type: 'trigger',
        nodeClass: ContactCreated::class,
    );

    expect($result)->toHaveKeys(['node_id', 'position']);
    expect($result['position']['y'])->toBe(40.0); // First trigger at y=40

    $node = WorkflowNode::find($result['node_id']);
    expect($node->type)->toBe('trigger');
    expect($node->node_class)->toBe(ContactCreated::class);
    expect($node->is_enabled)->toBeTrue();
});

it('adds an action node and auto-connects to leaf', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    // Add trigger first
    $trigger = $this->service->addNode($workflow, 'trigger', ContactCreated::class);

    // Add action — should auto-connect to trigger
    $action = $this->service->addNode($workflow, 'action', SendEmail::class);

    $edge = WorkflowEdge::where('from_node_id', $trigger['node_id'])
        ->where('to_node_id', $action['node_id'])
        ->first();

    expect($edge)->not->toBeNull();
});

it('auto-positions nodes vertically', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $trigger = $this->service->addNode($workflow, 'trigger', ContactCreated::class);
    $action = $this->service->addNode($workflow, 'action', SendEmail::class);

    expect($action['position']['y'])->toBeGreaterThan($trigger['position']['y']);
});

it('accepts custom position coordinates', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $result = $this->service->addNode(
        workflow: $workflow,
        type: 'trigger',
        nodeClass: ContactCreated::class,
        x: 300,
        y: 100,
    );

    expect($result['position']['x'])->toBe(300.0);
    expect($result['position']['y'])->toBe(100.0);
});

it('rejects invalid node types', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $this->service->addNode($workflow, 'invalid_type', ContactCreated::class);
})->throws(\InvalidArgumentException::class);

it('rejects non-existent node classes', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $this->service->addNode($workflow, 'trigger', 'App\\Nonexistent\\Class');
})->throws(\InvalidArgumentException::class);

// === connectNodes ===

it('connects two nodes', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $n1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ContactCreated::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 40,
    ]);

    $n2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    $result = $this->service->connectNodes($workflow, $n1->id, $n2->id);

    expect($result)->toHaveKey('edge_id');
    expect(WorkflowEdge::find($result['edge_id']))->not->toBeNull();
});

it('prevents duplicate edges', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $n1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ContactCreated::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 40,
    ]);

    $n2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    $r1 = $this->service->connectNodes($workflow, $n1->id, $n2->id);
    $r2 = $this->service->connectNodes($workflow, $n1->id, $n2->id);

    expect($r1['edge_id'])->toBe($r2['edge_id']);
    expect(WorkflowEdge::where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('supports branch parameter for if_else nodes', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $condition = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'if_else',
        'node_class' => FieldValue::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    $action = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [],
        'position_x' => 100,
        'position_y' => 360,
    ]);

    $result = $this->service->connectNodes($workflow, $condition->id, $action->id, 'true');

    $edge = WorkflowEdge::find($result['edge_id']);
    expect($edge->branch)->toBe('true');
});

// === configureNode ===

it('configures a node with config and label', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    $result = $this->service->configureNode(
        nodeId: $node->id,
        config: ['to' => '{{contact.email}}', 'subject' => 'Welcome!'],
        label: 'Welcome Email',
    );

    expect($result['config']['to'])->toBe('{{contact.email}}');
    expect($result['config']['subject'])->toBe('Welcome!');

    $node->refresh();
    expect($node->label)->toBe('Welcome Email');
});

it('merges config with existing values', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => ['to' => 'old@test.com'],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    $this->service->configureNode($node->id, ['subject' => 'New Subject']);

    $node->refresh();
    expect($node->config['to'])->toBe('old@test.com');
    expect($node->config['subject'])->toBe('New Subject');
});

// === removeNode ===

it('removes a node and its edges', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $n1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ContactCreated::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 40,
    ]);

    $n2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    WorkflowEdge::create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $n1->id,
        'to_node_id' => $n2->id,
        'sort_order' => 0,
    ]);

    $result = $this->service->removeNode($n2->id);

    expect($result['deleted'])->toBeTrue();
    expect(WorkflowNode::find($n2->id))->toBeNull();
    expect(WorkflowEdge::where('to_node_id', $n2->id)->exists())->toBeFalse();
});

// === removeEdge ===

it('removes an edge', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $n1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ContactCreated::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 40,
    ]);

    $n2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    $edge = WorkflowEdge::create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $n1->id,
        'to_node_id' => $n2->id,
        'sort_order' => 0,
    ]);

    $result = $this->service->removeEdge($edge->id);

    expect($result['deleted'])->toBeTrue();
    expect(WorkflowEdge::find($edge->id))->toBeNull();
});

// === getWorkflowState ===

it('returns full workflow state', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $n1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => ContactCreated::class,
        'config' => [],
        'position_x' => 250,
        'position_y' => 40,
    ]);

    $n2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => ['to' => '{{contact.email}}'],
        'position_x' => 250,
        'position_y' => 200,
    ]);

    WorkflowEdge::create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $n1->id,
        'to_node_id' => $n2->id,
        'sort_order' => 0,
    ]);

    $state = $this->service->getWorkflowState($workflow);

    expect($state)->toHaveKeys(['nodes', 'edges']);
    expect($state['nodes'])->toHaveCount(2);
    expect($state['edges'])->toHaveCount(1);
    expect($state['nodes'][0]['type'])->toBe('trigger');
    expect($state['edges'][0]['from_node_id'])->toBe($n1->id);
    expect($state['edges'][0]['to_node_id'])->toBe($n2->id);
});

// === Registry Discovery ===

it('lists available triggers', function () {
    $triggers = $this->service->listTriggers();

    expect($triggers)->toBeArray();
    expect(count($triggers))->toBeGreaterThan(0);

    $first = $triggers[0];
    expect($first)->toHaveKeys(['class', 'name', 'category', 'description', 'config_schema', 'dynamic_prefixes']);
});

it('lists available actions', function () {
    $actions = $this->service->listActions();

    expect($actions)->toBeArray();
    expect(count($actions))->toBeGreaterThan(0);
    expect($actions[0])->toHaveKeys(['class', 'name', 'category', 'description', 'config_schema']);
});

it('lists available conditions', function () {
    $conditions = $this->service->listConditions();

    expect($conditions)->toBeArray();
    expect(count($conditions))->toBeGreaterThan(0);
    expect($conditions[0])->toHaveKeys(['class', 'name', 'category', 'description', 'config_schema']);
});

it('lists available waits', function () {
    $waits = $this->service->listWaits();

    expect($waits)->toBeArray();
    expect(count($waits))->toBeGreaterThan(0);
    expect($waits[0])->toHaveKeys(['class', 'name', 'category', 'description', 'config_schema']);
});

it('lists dynamic values for a trigger', function () {
    $fields = $this->service->listDynamicValues(ContactCreated::class);

    expect($fields)->toBeArray();
    expect(count($fields))->toBeGreaterThan(0);
    expect($fields[0])->toHaveKeys(['prefix', 'key', 'path', 'label']);
});

// === generateMissingFeaturePrompt ===

it('generates a missing feature prompt', function () {
    $prompt = $this->service->generateMissingFeaturePrompt(
        featureName: 'Send Slack Message',
        featureType: 'action',
        description: 'Send a message to a Slack channel',
    );

    expect($prompt)->toContain('Send Slack Message');
    expect($prompt)->toContain('action');
    expect($prompt)->toContain('ActionInterface');
    expect($prompt)->toContain('app/Automation/Actions/');
});
