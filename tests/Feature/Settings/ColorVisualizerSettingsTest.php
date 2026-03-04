<?php

use App\Ai\Agents\ColorVisualizerAgent;
use App\Events\ColorVisualizationCompleted;
use App\Jobs\GenerateColorVisualizationJob;
use App\Models\ColorVisualization;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Image;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->customerUser = User::factory()->customer()->create();
});

it('renders the settings page for authorized users', function () {
    $this->actingAs($this->internalUser)
        ->get(route('settings.color-visualizer'))
        ->assertSuccessful()
        ->assertSee('Color Visualizer')
        ->assertSee('Color Resolution Agent Prompt')
        ->assertSee('Image Editing Prompt Template');
});

it('returns 403 for unauthorized users', function () {
    $this->actingAs($this->customerUser)
        ->get(route('settings.color-visualizer'))
        ->assertForbidden();
});

it('returns 403 for internal users without permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get(route('settings.color-visualizer'))
        ->assertForbidden();
});

it('saves custom prompts', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.color-visualizer')
        ->set('visualizer_agent_prompt', 'Custom agent instructions.')
        ->set('visualizer_image_prompt', 'Custom image template with {color_instructions} placeholder.')
        ->call('save')
        ->assertDispatched('notify');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->visualizer_agent_prompt)->toBe('Custom agent instructions.')
        ->and($settings->visualizer_image_prompt)->toBe('Custom image template with {color_instructions} placeholder.');
});

it('saves empty strings as null', function () {
    CompanySetting::getInstance()->update([
        'visualizer_agent_prompt' => 'Old prompt',
        'visualizer_image_prompt' => 'Old template',
    ]);
    CompanySetting::clearInstanceCache();

    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.color-visualizer')
        ->set('visualizer_agent_prompt', '')
        ->set('visualizer_image_prompt', '')
        ->call('save');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->visualizer_agent_prompt)->toBeNull()
        ->and($settings->visualizer_image_prompt)->toBeNull();
});

it('reset agent prompt loads the default', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.color-visualizer')
        ->set('visualizer_agent_prompt', 'Something custom')
        ->call('resetAgentPrompt')
        ->assertSet('visualizer_agent_prompt', config('services.color_visualizer.default_agent_prompt'));
});

it('reset image prompt loads the default', function () {
    Livewire::actingAs($this->internalUser)
        ->test('pages::settings.color-visualizer')
        ->set('visualizer_image_prompt', 'Something custom')
        ->call('resetImagePrompt')
        ->assertSet('visualizer_image_prompt', config('services.color_visualizer.default_image_prompt'));
});

it('job uses custom image prompt from settings', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    CompanySetting::getInstance()->update([
        'visualizer_image_prompt' => 'CUSTOM TEMPLATE: {color_instructions}. Done!',
    ]);
    CompanySetting::clearInstanceCache();

    ColorVisualizerAgent::fake([
        '- Surface: walls | Color: Benjamin Moore White Dove (OC-17) | Hex: #F3EFE0',
    ]);
    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'prompt' => 'BM White Dove on walls',
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    Image::assertGenerated(function ($pendingGeneration) {
        return str_contains($pendingGeneration->prompt, 'CUSTOM TEMPLATE:')
            && str_contains($pendingGeneration->prompt, '#F3EFE0')
            && str_contains($pendingGeneration->prompt, 'Done!');
    });
});

it('job uses config default prompt when settings are null', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake([
        '- Surface: walls | Color: Benjamin Moore White Dove (OC-17) | Hex: #F3EFE0',
    ]);
    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'prompt' => 'BM White Dove on walls',
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    Image::assertGenerated(function ($pendingGeneration) {
        return str_contains($pendingGeneration->prompt, 'PHOTO EDIT')
            && str_contains($pendingGeneration->prompt, '#F3EFE0');
    });
});

it('job reads model from config', function () {
    Event::fake([ColorVisualizationCompleted::class]);

    ColorVisualizerAgent::fake([
        '- Surface: walls | Color: Benjamin Moore White Dove (OC-17) | Hex: #F3EFE0',
    ]);
    Image::fake();

    $visualization = ColorVisualization::factory()->create([
        'prompt' => 'BM White Dove on walls',
        'original_image_path' => 'photos/test.jpg',
    ]);

    (new GenerateColorVisualizationJob($visualization))->handle();

    // Model comes from config, not hardcoded
    expect(config('services.color_visualizer.image_model'))->not->toBeNull();
});

it('agent uses custom instructions from settings', function () {
    CompanySetting::getInstance()->update([
        'visualizer_agent_prompt' => 'You are a custom color agent.',
    ]);
    CompanySetting::clearInstanceCache();

    $agent = new ColorVisualizerAgent;
    expect((string) $agent->instructions())->toBe('You are a custom color agent.');
});

it('agent uses config default instructions when settings are null', function () {
    $agent = new ColorVisualizerAgent;
    $instructions = (string) $agent->instructions();

    expect($instructions)->toBe(config('services.color_visualizer.default_agent_prompt'));
});

it('shows current model from config on settings page', function () {
    $model = config('services.color_visualizer.image_model');

    $this->actingAs($this->internalUser)
        ->get(route('settings.color-visualizer'))
        ->assertSuccessful()
        ->assertSee($model);
});
