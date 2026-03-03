<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Services\StudioAiService;
use Livewire\Livewire;

it('renders the AI prompt bar', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->assertOk()
        ->assertSee('Ask AI');
});

it('validates prompt is required', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', '')
        ->call('generate')
        ->assertHasErrors(['prompt']);
});

it('validates prompt minimum length', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', 'ab')
        ->call('generate')
        ->assertHasErrors(['prompt']);
});

it('generates a project and dispatches event', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'creation_mode' => 'ai_generated']);

    $this->mock(StudioAiService::class, function ($mock) use ($project) {
        $mock->shouldReceive('generateFromPrompt')
            ->once()
            ->andReturn($project);
    });

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', 'Make a before and after video')
        ->call('generate')
        ->assertSet('isProcessing', false)
        ->assertSet('generatedProjectId', $project->id)
        ->assertDispatched('ai-project-created');
});

it('handles errors gracefully', function () {
    $user = User::factory()->internal()->create();

    $this->mock(StudioAiService::class, function ($mock) {
        $mock->shouldReceive('generateFromPrompt')
            ->once()
            ->andThrow(new \Exception('API error'));
    });

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', 'Make a video')
        ->call('generate')
        ->assertSet('isProcessing', false)
        ->assertSet('generatedProjectId', null)
        ->assertSet('statusMessage', 'Something went wrong. Please try again.');
});

it('resets prompt state', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', 'test')
        ->set('statusMessage', 'Done')
        ->set('generatedProjectId', 1)
        ->call('resetPrompt')
        ->assertSet('prompt', '')
        ->assertSet('statusMessage', null)
        ->assertSet('generatedProjectId', null);
});

it('shows success message with link after generation', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'creation_mode' => 'ai_generated']);

    $this->mock(StudioAiService::class, function ($mock) use ($project) {
        $mock->shouldReceive('generateFromPrompt')
            ->once()
            ->andReturn($project);
    });

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->set('prompt', 'Create a walkthrough video')
        ->call('generate')
        ->assertSet('statusMessage', 'Video project created!')
        ->assertSee('Open in editor');
});
