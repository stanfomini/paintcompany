<?php

use App\Ai\Agents\WorkflowBuilderAgent;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->actingAs($this->user);
});

it('can be instantiated with a workflow', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $agent = new WorkflowBuilderAgent($workflow);

    expect($agent)->toBeInstanceOf(WorkflowBuilderAgent::class);
    expect($agent->workflow->id)->toBe($workflow->id);
});

it('has 12 tools registered', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $agent = new WorkflowBuilderAgent($workflow);
    $tools = iterator_to_array($agent->tools());

    expect(count($tools))->toBe(12);
});

it('has conversation history support', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $agent = (new WorkflowBuilderAgent($workflow))->withHistory([
        ['role' => 'user', 'content' => 'Add a trigger'],
        ['role' => 'assistant', 'content' => 'Done!'],
    ]);

    $messages = iterator_to_array($agent->messages());
    expect(count($messages))->toBe(2);
});

it('generates instructions with company name', function () {
    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $agent = new WorkflowBuilderAgent($workflow);
    $instructions = (string) $agent->instructions();

    expect($instructions)->toContain('workflow builder assistant');
    expect($instructions)->toContain('trigger');
    expect($instructions)->toContain('if_else');
});

it('can be faked for testing', function () {
    WorkflowBuilderAgent::fake([
        'I created a workflow with a Contact Created trigger and a Send Email action.',
    ]);

    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $this->user->id,
    ]);

    $agent = new WorkflowBuilderAgent($workflow);
    $response = $agent->prompt('Create a workflow that sends an email when a contact is created');

    expect((string) $response)->toContain('Contact Created');

    WorkflowBuilderAgent::assertPrompted(function ($prompt) {
        return $prompt->contains('sends an email');
    });
});
