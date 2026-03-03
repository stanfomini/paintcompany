<?php

use App\Models\User;
use App\Models\VideoProject;
use Livewire\Livewire;

it('adds a text overlay to a scene', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('overlayText', 'Hello World')
        ->set('overlayPosition', 'center')
        ->call('addTextOverlay');

    $overlay = $project->scenes()->first()->overlays()->first();
    expect($overlay)->not->toBeNull();
    expect($overlay->type)->toBe('text');
    expect($overlay->content)->toBe('Hello World');
});

it('removes a text overlay', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('overlayText', 'To Remove')
        ->call('addTextOverlay')
        ->call('removeOverlay', 0);

    expect($project->scenes()->first()->overlays()->count())->toBe(0);
});

it('validates overlay text is required', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('overlayText', '')
        ->call('addTextOverlay')
        ->assertHasErrors(['overlayText']);
});

it('resets form after adding overlay', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('overlayText', 'Test')
        ->call('addTextOverlay')
        ->assertSet('overlayText', '')
        ->assertSet('showTextEditor', false);
});
