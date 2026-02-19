<?php

use App\Automation\Actions\CreateCustomer;
use App\Automation\Actions\SendNotification;
use App\Automation\Registry;
use App\Automation\Triggers\ApplicationReceived;
use App\Automation\Triggers\ContactCreated;
use App\Automation\Triggers\DealStageChanged;
use App\Automation\Triggers\DoorKnockRecorded;
use App\Automation\Triggers\EstimateSent;
use App\Automation\Triggers\EstimateSigned;
use App\Automation\Triggers\ExternalApiPoll;
use App\Automation\Triggers\InboundWebhook;
use App\Automation\Triggers\JobCompleted;
use App\Automation\Triggers\JobCreated;
use App\Automation\Triggers\JobStageChanged;
use App\Automation\Triggers\ScheduledCron;
use App\Automation\Triggers\TaskCompleted;
use App\Automation\Triggers\TaskOverdue;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowNode;
use Livewire\Livewire;

// ============================================================
// Step 1: Each trigger returns correct prefixes
// ============================================================

it('returns correct dynamic prefixes for DoorKnockRecorded', function () {
    expect(DoorKnockRecorded::getAvailableDynamicPrefixes())
        ->toBe(['doorknock', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for DealStageChanged', function () {
    expect(DealStageChanged::getAvailableDynamicPrefixes())
        ->toBe(['deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for ContactCreated', function () {
    expect(ContactCreated::getAvailableDynamicPrefixes())
        ->toBe(['contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for EstimateSent', function () {
    expect(EstimateSent::getAvailableDynamicPrefixes())
        ->toBe(['deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for EstimateSigned', function () {
    expect(EstimateSigned::getAvailableDynamicPrefixes())
        ->toBe(['deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for JobCreated', function () {
    expect(JobCreated::getAvailableDynamicPrefixes())
        ->toBe(['job', 'deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for JobCompleted', function () {
    expect(JobCompleted::getAvailableDynamicPrefixes())
        ->toBe(['job', 'deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for JobStageChanged', function () {
    expect(JobStageChanged::getAvailableDynamicPrefixes())
        ->toBe(['job', 'deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for TaskCompleted', function () {
    expect(TaskCompleted::getAvailableDynamicPrefixes())
        ->toBe(['deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for TaskOverdue', function () {
    expect(TaskOverdue::getAvailableDynamicPrefixes())
        ->toBe(['deal', 'contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for ScheduledCron', function () {
    expect(ScheduledCron::getAvailableDynamicPrefixes())
        ->toBe(['workflow', 'datetime']);
});

it('returns correct dynamic prefixes for ApplicationReceived', function () {
    expect(ApplicationReceived::getAvailableDynamicPrefixes())
        ->toBe(['contact', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for InboundWebhook', function () {
    expect(InboundWebhook::getAvailableDynamicPrefixes())
        ->toBe(['webhook', 'workflow', 'datetime']);
});

it('returns correct dynamic prefixes for ExternalApiPoll', function () {
    expect(ExternalApiPoll::getAvailableDynamicPrefixes())
        ->toBe(['webhook', 'workflow', 'datetime']);
});

// ============================================================
// Step 2: Registry::getAvailableFieldsForTrigger returns correct structure
// ============================================================

it('returns fields with correct structure from getAvailableFieldsForTrigger', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger(DoorKnockRecorded::class);

    expect($fields)->toBeArray()->not->toBeEmpty();

    $first = $fields[0];
    expect($first)->toHaveKeys(['prefix', 'key', 'path', 'label']);
    expect($first['prefix'])->toBeString();
    expect($first['key'])->toBeString();
    expect($first['path'])->toContain('.');
    expect($first['label'])->toBeString();
});

it('returns doorknock fields for DoorKnockRecorded trigger', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger(DoorKnockRecorded::class);
    $paths = collect($fields)->pluck('path')->toArray();

    expect($paths)->toContain('doorknock.email');
    expect($paths)->toContain('doorknock.phone');
    expect($paths)->toContain('doorknock.address');
    expect($paths)->toContain('doorknock.first_name');
    expect($paths)->toContain('doorknock.last_name');
    expect($paths)->toContain('workflow.name');
    expect($paths)->toContain('datetime.today');
});

it('returns deal and contact fields for DealStageChanged trigger', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger(DealStageChanged::class);
    $paths = collect($fields)->pluck('path')->toArray();

    expect($paths)->toContain('deal.title');
    expect($paths)->toContain('deal.stage');
    expect($paths)->toContain('contact.name');
    expect($paths)->toContain('contact.email');
    expect($paths)->toContain('workflow.name');
});

it('returns job, deal, and contact fields for JobCreated trigger', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger(JobCreated::class);
    $paths = collect($fields)->pluck('path')->toArray();

    expect($paths)->toContain('job.title');
    expect($paths)->toContain('job.stage');
    expect($paths)->toContain('deal.title');
    expect($paths)->toContain('contact.email');
});

it('returns workflow and datetime fields for InboundWebhook trigger', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger(InboundWebhook::class);
    $paths = collect($fields)->pluck('path')->toArray();

    // WebhookValues has empty getAvailableKeys(), so no webhook.* fields
    expect($paths)->toContain('workflow.name');
    expect($paths)->toContain('workflow.run_id');
    expect($paths)->toContain('datetime.now');
    expect($paths)->toContain('datetime.today');
});

it('returns only workflow and datetime fields for ScheduledCron trigger', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger(ScheduledCron::class);
    $prefixes = collect($fields)->pluck('prefix')->unique()->values()->toArray();

    expect($prefixes)->toBe(['workflow', 'datetime']);
});

it('returns empty array for an invalid trigger class', function () {
    $registry = app(Registry::class);
    $fields = $registry->getAvailableFieldsForTrigger('App\\NonExistent\\Trigger');

    expect($fields)->toBe([]);
});

// ============================================================
// Steps 3 & 4: Livewire auto-mapping integration
// ============================================================

it('auto-maps fields when opening a CreateCustomer action with DoorKnock trigger', function () {
    $user = User::factory()->internal()->create();
    $workflow = Workflow::factory()->create(['created_by' => $user->id]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DoorKnockRecorded::class,
        'config' => [],
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

    $component = Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow]);

    $component->call('openNodeConfig', $actionNode->id);

    // Fields that match by key name should be auto-mapped
    $nodeConfig = $component->get('nodeConfig');
    expect($nodeConfig['first_name'])->toBe('{{doorknock.first_name}}');
    expect($nodeConfig['last_name'])->toBe('{{doorknock.last_name}}');
    expect($nodeConfig['email'])->toBe('{{doorknock.email}}');
    expect($nodeConfig['phone'])->toBe('{{doorknock.phone}}');
    expect($nodeConfig['address'])->toBe('{{doorknock.address}}');
    expect($nodeConfig['city'])->toBe('{{doorknock.city}}');
    expect($nodeConfig['state'])->toBe('{{doorknock.state}}');
    expect($nodeConfig['zip'])->toBe('{{doorknock.zip}}');

    // availableDynamicFields should be populated
    $dynamicFields = $component->get('availableDynamicFields');
    expect($dynamicFields)->not->toBeNull();
    expect(collect($dynamicFields)->pluck('path')->toArray())->toContain('doorknock.email');
});

it('does not overwrite existing config values during auto-map', function () {
    $user = User::factory()->internal()->create();
    $workflow = Workflow::factory()->create(['created_by' => $user->id]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DoorKnockRecorded::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $actionNode = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => CreateCustomer::class,
        'config' => ['email' => 'hardcoded@test.com', 'first_name' => '{{doorknock.customer_name}}'],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow]);

    $component->call('openNodeConfig', $actionNode->id);

    $nodeConfig = $component->get('nodeConfig');
    // Existing values should be preserved
    expect($nodeConfig['email'])->toBe('hardcoded@test.com');
    expect($nodeConfig['first_name'])->toBe('{{doorknock.customer_name}}');
    // Empty fields should still auto-map
    expect($nodeConfig['phone'])->toBe('{{doorknock.phone}}');
});

it('does not auto-map fields with fixed options (dropdowns)', function () {
    $user = User::factory()->internal()->create();
    $workflow = Workflow::factory()->create(['created_by' => $user->id]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DoorKnockRecorded::class,
        'config' => [],
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

    $component = Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow]);

    $component->call('openNodeConfig', $actionNode->id);

    $nodeConfig = $component->get('nodeConfig');
    // Boolean field 'deduplicate_by_email' should not be auto-mapped
    expect($nodeConfig)->not->toHaveKey('deduplicate_by_email', '{{doorknock.deduplicate_by_email}}');
});

it('loads dynamic fields for DealStageChanged trigger with SendNotification action', function () {
    $user = User::factory()->internal()->create();
    $workflow = Workflow::factory()->create(['created_by' => $user->id]);

    WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DealStageChanged::class,
        'config' => ['target_stage' => 'proposal'],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $actionNode = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'action',
        'node_class' => SendNotification::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 120,
        'is_enabled' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow]);

    $component->call('openNodeConfig', $actionNode->id);

    $dynamicFields = $component->get('availableDynamicFields');
    expect($dynamicFields)->not->toBeNull();

    $paths = collect($dynamicFields)->pluck('path')->toArray();
    expect($paths)->toContain('deal.title');
    expect($paths)->toContain('contact.email');
    expect($paths)->toContain('workflow.name');
    expect($paths)->toContain('datetime.today');
});

it('does not load dynamic fields for trigger nodes', function () {
    $user = User::factory()->internal()->create();
    $workflow = Workflow::factory()->create(['created_by' => $user->id]);

    $triggerNode = WorkflowNode::create([
        'workflow_id' => $workflow->id,
        'type' => 'trigger',
        'node_class' => DoorKnockRecorded::class,
        'config' => [],
        'position_x' => 0,
        'position_y' => 0,
        'is_enabled' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow]);

    $component->call('openNodeConfig', $triggerNode->id);

    // Trigger nodes should not have dynamic fields loaded
    expect($component->get('availableDynamicFields'))->toBeNull();
});
