<?php

use App\Automation\Actions\CreateCustomer;
use App\Automation\Actions\SendEmail;
use App\Automation\Triggers\DealStageChanged;
use App\Automation\Triggers\InboundWebhook;
use App\Models\AutomationWebhook;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo('settings.workflows');
    $this->actingAs($this->user);
});

it('renders the workflows list page', function () {
    $this->get('/settings/workflows')
        ->assertSuccessful()
        ->assertSee('Workflows');
});

it('lists existing workflows', function () {
    Workflow::create([
        'name' => 'Test Workflow',
        'is_active' => true,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now(),
    ]);

    $this->get('/settings/workflows')
        ->assertSee('Test Workflow')
        ->assertSee('Active');
});

it('creates a new workflow', function () {
    Livewire::test('pages::settings.workflows')
        ->call('openCreateModal')
        ->assertSet('showCreateModal', true)
        ->set('newName', 'My Automation')
        ->set('newDescription', 'A test workflow')
        ->call('createWorkflow')
        ->assertSet('view', 'builder');

    expect(Workflow::where('name', 'My Automation')->exists())->toBeTrue();
});

it('validates workflow name is required', function () {
    Livewire::test('pages::settings.workflows')
        ->call('openCreateModal')
        ->set('newName', '')
        ->call('createWorkflow')
        ->assertHasErrors(['newName']);
});

it('can toggle workflow active state', function () {
    $workflow = Workflow::create([
        'name' => 'Toggle Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('toggleActive', $workflow->id);

    expect($workflow->fresh()->is_active)->toBeTrue();
    expect($workflow->fresh()->published_at)->not->toBeNull();
});

it('can duplicate a workflow', function () {
    $original = Workflow::create([
        'name' => 'Original',
        'is_active' => true,
        'is_system' => true,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now(),
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $original->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => ['target_stage' => 'lead'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('duplicateWorkflow', $original->id);

    expect(Workflow::count())->toBe(2);

    $copy = Workflow::where('name', 'Original (Copy)')->first();
    expect($copy)->not->toBeNull();
    expect($copy->is_system)->toBeFalse();
    expect($copy->is_active)->toBeFalse();
    expect($copy->nodes()->count())->toBe(1);
});

it('can delete a non-system workflow', function () {
    $workflow = Workflow::create([
        'name' => 'Deletable',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('confirmDelete', $workflow->id, 'Deletable')
        ->assertSet('showDeleteModal', true)
        ->call('deleteWorkflow');

    expect(Workflow::withTrashed()->find($workflow->id)->trashed())->toBeTrue();
});

it('cannot delete a system workflow', function () {
    $workflow = Workflow::create([
        'name' => 'System',
        'is_active' => true,
        'is_system' => true,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now(),
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('confirmDelete', $workflow->id, 'System')
        ->call('deleteWorkflow');

    expect(Workflow::find($workflow->id))->not->toBeNull();
});

it('opens the builder view when editing a workflow', function () {
    $workflow = Workflow::create([
        'name' => 'Edit Me',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->assertSet('view', 'builder')
        ->assertSet('editingWorkflowId', $workflow->id)
        ->assertSet('workflowName', 'Edit Me');
});

it('can add a node to a workflow', function () {
    $workflow = Workflow::create([
        'name' => 'Builder Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('addNode', 'trigger', DealStageChanged::class);

    expect($workflow->nodes()->count())->toBe(1);
    expect($workflow->nodes()->first()->type)->toBe('trigger');
    expect($workflow->nodes()->first()->node_class)->toBe(DealStageChanged::class);
});

it('can configure a node', function () {
    $workflow = Workflow::create([
        'name' => 'Config Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('openNodeConfig', $node->id)
        ->assertSet('showNodeConfig', true)
        ->set('nodeConfig.target_stage', 'lead')
        ->call('saveNodeConfig');

    expect($node->fresh()->config['target_stage'])->toBe('lead');
});

it('can connect two nodes', function () {
    $workflow = Workflow::create([
        'name' => 'Connect Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $action = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('connectNodes', $trigger->id, $action->id);

    expect(WorkflowEdge::where('workflow_id', $workflow->id)->count())->toBe(1);
    $edge = WorkflowEdge::where('workflow_id', $workflow->id)->first();
    expect($edge->from_node_id)->toBe($trigger->id);
    expect($edge->to_node_id)->toBe($action->id);
});

it('prevents duplicate edges', function () {
    $workflow = Workflow::create([
        'name' => 'Dedup Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $node1 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $node2 = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('connectNodes', $node1->id, $node2->id)
        ->call('connectNodes', $node1->id, $node2->id);

    expect(WorkflowEdge::where('workflow_id', $workflow->id)->count())->toBe(1);
});

it('can delete a non-trigger node', function () {
    $workflow = Workflow::create([
        'name' => 'Delete Node',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $action = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => \App\Automation\Actions\CreateTask::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    WorkflowEdge::create([
        'workflow_id' => $workflow->id,
        'from_node_id' => $trigger->id,
        'to_node_id' => $action->id,
        'sort_order' => 0,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('deleteNode', $action->id);

    expect(WorkflowNode::find($action->id))->toBeNull();
    expect(WorkflowEdge::where('workflow_id', $workflow->id)->count())->toBe(0);
});

it('can delete a trigger node', function () {
    $workflow = Workflow::create([
        'name' => 'Protected Trigger',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $trigger = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('deleteNode', $trigger->id);

    expect(WorkflowNode::find($trigger->id))->toBeNull();
});

it('can save workflow settings from builder', function () {
    $workflow = Workflow::create([
        'name' => 'Old Name',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->set('workflowName', 'New Name')
        ->set('workflowDescription', 'Updated description')
        ->set('workflowIsActive', true)
        ->call('saveWorkflowSettings');

    $workflow->refresh();
    expect($workflow->name)->toBe('New Name');
    expect($workflow->description)->toBe('Updated description');
    expect($workflow->is_active)->toBeTrue();
    expect($workflow->published_at)->not->toBeNull();
});

it('shows run history for a workflow', function () {
    $workflow = Workflow::create([
        'name' => 'With Runs',
        'is_active' => true,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
        'published_at' => now(),
    ]);

    $run = WorkflowRun::create([
        'workflow_id' => $workflow->id,
        'subject_type' => User::class,
        'subject_id' => $this->user->id,
        'status' => 'completed',
        'context' => [],
        'triggered_at' => now(),
        'completed_at' => now(),
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('viewRunHistory', $workflow->id)
        ->assertSet('showRunHistory', true)
        ->assertSee('Completed');
});

it('denies access to non-internal users', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get('/settings/workflows')
        ->assertForbidden();
});

// === Webhook Config UX Tests ===

it('auto-creates automation webhook when opening inbound webhook trigger config', function () {
    $workflow = Workflow::create([
        'name' => 'Webhook Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => InboundWebhook::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $component = Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('openNodeConfig', $node->id);

    // Webhook should be auto-created
    expect(AutomationWebhook::count())->toBe(1);

    $webhook = AutomationWebhook::first();
    $component
        ->assertSet('webhookUrl', $webhook->getWebhookUrl())
        ->assertSet('webhookModelId', $webhook->id)
        ->assertSet('nodeConfig.webhook_token', $webhook->token);
});

it('loads existing webhook when trigger already has token', function () {
    $webhook = AutomationWebhook::create([
        'name' => 'Existing Webhook',
        'is_active' => true,
        'created_by' => $this->user->id,
    ]);

    $workflow = Workflow::create([
        'name' => 'Existing Token Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $node = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => InboundWebhook::class,
        'config' => ['webhook_token' => $webhook->token],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('openNodeConfig', $node->id)
        ->assertSet('webhookModelId', $webhook->id)
        ->assertSet('webhookUrl', $webhook->getWebhookUrl());

    // Should NOT create a second webhook
    expect(AutomationWebhook::count())->toBe(1);
});

it('loads webhook sample payload fields for record-creation action config', function () {
    $webhook = AutomationWebhook::create([
        'name' => 'Payload Test Webhook',
        'is_active' => true,
        'last_payload' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@test.com',
            'phone' => '555-1234',
        ],
        'created_by' => $this->user->id,
    ]);

    $workflow = Workflow::create([
        'name' => 'Field Mapping Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    // Create trigger node with webhook token
    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => InboundWebhook::class,
        'config' => ['webhook_token' => $webhook->token],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    // Create action node
    $actionNode = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => CreateCustomer::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    $component = Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('openNodeConfig', $actionNode->id);

    $fields = $component->get('webhookAvailableFields');
    expect($fields)->toBeArray()
        ->and(count($fields))->toBe(4)
        ->and(collect($fields)->pluck('path')->toArray())
        ->toContain('first_name', 'last_name', 'email', 'phone');
});

it('saves webhook field mapping as dynamic value placeholders', function () {
    $webhook = AutomationWebhook::create([
        'name' => 'Save Mapping Test',
        'is_active' => true,
        'last_payload' => ['first_name' => 'Jane', 'email' => 'jane@test.com'],
        'created_by' => $this->user->id,
    ]);

    $workflow = Workflow::create([
        'name' => 'Save Mapping Workflow',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => InboundWebhook::class,
        'config' => ['webhook_token' => $webhook->token],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $actionNode = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => CreateCustomer::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    Livewire::test('pages::settings.workflows')
        ->call('editWorkflow', $workflow->id)
        ->call('openNodeConfig', $actionNode->id)
        ->set('nodeConfig.first_name', '{{webhook.first_name}}')
        ->set('nodeConfig.email', '{{webhook.email}}')
        ->call('saveNodeConfig');

    $actionNode->refresh();
    expect($actionNode->config['first_name'])->toBe('{{webhook.first_name}}')
        ->and($actionNode->config['email'])->toBe('{{webhook.email}}');
});

it('shows inline email body preview for SendEmail action node', function () {
    $workflow = Workflow::factory()->create(['created_by' => $this->user->id]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $emailNode = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendEmail::class,
        'config' => [
            'to' => '{{contact.email}}',
            'subject' => 'Welcome!',
            'body' => '<p>Hello <strong>there</strong></p>',
        ],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    $component = Livewire::test('pages::settings.workflow-builder', ['workflow' => $workflow])
        ->call('openNodeConfig', $emailNode->id);

    // The body HTML should be present inline as a preview (not hidden behind a modal)
    $component->assertSet('nodeConfig.body', '<p>Hello <strong>there</strong></p>')
        ->assertSeeHtml('<p>Hello <strong>there</strong></p>');
});
