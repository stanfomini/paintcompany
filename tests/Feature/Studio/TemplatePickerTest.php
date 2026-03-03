<?php

use App\Models\StudioTemplate;
use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('renders active templates', function () {
    $user = User::factory()->internal()->create();
    StudioTemplate::factory()->create(['name' => 'Before & After', 'is_active' => true]);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->assertSee('Before & After');
});

it('hides inactive templates', function () {
    $user = User::factory()->internal()->create();
    StudioTemplate::factory()->inactive()->create(['name' => 'Hidden Template']);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->assertDontSee('Hidden Template');
});

it('creates a project from a template', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->beforeAfter()->create();

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->call('useTemplate', $template->id);

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project)->not->toBeNull();
    expect($project->creation_mode)->toBe('template');
    expect($project->template_id)->toBe($template->id);
});

it('auto-creates scenes from template structure', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'Before shot', 'duration_ms' => 3000],
                ['type' => 'recording', 'label' => 'Commentary'],
                ['type' => 'media', 'label' => 'After shot', 'duration_ms' => 3000],
            ],
            'aspect_ratio' => '9:16',
        ],
    ]);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->call('useTemplate', $template->id);

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project->scenes()->count())->toBe(3);
    expect($project->scenes()->orderBy('sort_order')->first()->name)->toBe('Before shot');
    expect($project->aspect_ratio)->toBe('9:16');
});

it('passes deal_id in metadata when selectedDealId is set', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.template-picker', ['selectedDealId' => 42])
        ->call('useTemplate', $template->id);

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project->metadata['deal_id'])->toBe(42);
});

it('sets empty metadata when no deal is selected', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create();

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->call('useTemplate', $template->id);

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project->metadata)->toBeEmpty();
});

it('sets project title from template name', function () {
    $user = User::factory()->internal()->create();
    $template = StudioTemplate::factory()->create(['name' => 'Job Walkthrough']);

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->call('useTemplate', $template->id);

    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project->title)->toBe('Job Walkthrough Video');
});

it('shows empty state when no templates exist', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.template-picker')
        ->assertSee('No templates available.');
});
