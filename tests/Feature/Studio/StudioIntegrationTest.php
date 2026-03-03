<?php

use App\Models\Deal;
use App\Models\PropertyPhoto;
use App\Models\StudioTemplate;
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('renders the studio home page', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)->get('/studio')->assertOk();
});

it('creates a blank project and redirects to editor', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->call('createBlank');

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project)->not->toBeNull();
    expect($project->creation_mode)->toBe('quick');
});

it('opens quick creator for a project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('studio.create', $project))
        ->assertOk()
        ->assertSeeLivewire('studio.quick-creator');
});

it('creates project from deal and opens editor', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $this->actingAs($user)
        ->get(route('studio.create-from-deal', $deal))
        ->assertRedirect();

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project)->not->toBeNull();
    expect($project->metadata['deal_id'])->toBe($deal->id);
});

it('creates project from template with scenes', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'Intro', 'duration_ms' => 3000],
                ['type' => 'recording', 'label' => 'Commentary'],
            ],
            'aspect_ratio' => '9:16',
        ],
    ]);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->call('useTemplate', $template->id);

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project->creation_mode)->toBe('template');
    expect($project->scenes()->count())->toBe(2);
});

it('adds and removes clips in quick creator', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addClip', 'property-photo', $photo->id)
        ->call('removeClip', 0);

    expect($project->scenes()->first()->clips()->count())->toBe(0);
});

it('media browser dispatches selection event', function () {
    $user = User::factory()->internal()->create();
    $photo = PropertyPhoto::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.media-browser')
        ->call('selectMedia', 'property-photo', $photo->id)
        ->assertDispatched('media-selected');
});

it('ai prompt bar renders and validates', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.ai-prompt-bar')
        ->assertOk()
        ->set('prompt', '')
        ->call('generate')
        ->assertHasErrors(['prompt']);
});

it('all studio routes are accessible', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get('/studio')->assertOk();
    $this->actingAs($user)->get(route('studio.create', $project))->assertOk();
});

it('studio feed shows templates button', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->assertSee('Templates');
});

it('studio feed opens template picker', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->call('openTemplates')
        ->assertSet('showTemplatePicker', true);
});

it('studio feed includes ai prompt bar', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/studio')
        ->assertOk()
        ->assertSeeLivewire('studio.ai-prompt-bar');
});
