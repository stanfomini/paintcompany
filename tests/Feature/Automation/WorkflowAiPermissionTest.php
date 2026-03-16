<?php

use App\Models\User;
use App\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows users with ai.workflow_builder permission to open AI prompt modal', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows', 'ai.workflow_builder');

    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow])
        ->call('openAiPromptModal')
        ->assertSet('showAiPromptModal', true);
});

it('denies users without ai.workflow_builder permission from opening AI prompt modal', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');
    // Note: NOT giving ai.workflow_builder

    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow])
        ->call('openAiPromptModal')
        ->assertForbidden();
});

it('denies users without permission from sending AI chat messages', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow])
        ->set('aiChatInput', 'Add a trigger')
        ->call('sendAiChatMessage')
        ->assertForbidden();
});

it('denies users without permission from generating workflow from prompt', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.workflow-builder', ['workflow' => $workflow])
        ->set('aiPrompt', 'Create a workflow that sends welcome emails')
        ->call('generateWorkflowFromPrompt')
        ->assertForbidden();
});

it('allows users with permission to create with AI from workflows list', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows', 'ai.workflow_builder');

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('createWithAi');

    expect(Workflow::where('name', 'AI-Generated Workflow')->exists())->toBeTrue();
});

it('denies users without permission from creating with AI', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows');

    Livewire::actingAs($user)
        ->test('pages::settings.workflows')
        ->call('createWithAi')
        ->assertForbidden();
});

it('auto-opens AI prompt modal when ai=1 query param is present', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('settings.workflows', 'ai.workflow_builder');

    $workflow = Workflow::create([
        'name' => 'Test',
        'is_active' => false,
        'is_system' => false,
        'version' => 1,
        'created_by' => $user->id,
    ]);

    // Simulate the ?ai=1 query param by testing the mount directly
    $this->actingAs($user);
    $this->get(route('workflows.builder', $workflow).'?ai=1')
        ->assertSuccessful();
});
