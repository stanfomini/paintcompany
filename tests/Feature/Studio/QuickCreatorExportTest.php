<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('opens export modal', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('openExportModal')
        ->assertSet('showExportModal', true);
});

it('starts render and sets status to rendering', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'status' => 'draft']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('startRender')
        ->assertSet('isRendering', true);

    expect($project->fresh()->status)->toBe('rendering');
});

it('publishes rendered video to media hub', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'status' => 'rendered',
        'video_recording_id' => $recording->id,
    ]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('publishToHub')
        ->assertSet('publishToHub', true);

    expect($recording->fresh()->available_in_hub)->toBeTrue();
});

it('unpublishes from media hub', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'available_in_hub' => true,
    ]);
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'status' => 'rendered',
        'video_recording_id' => $recording->id,
    ]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->set('publishToHub', true)
        ->call('unpublishFromHub');

    expect($recording->fresh()->available_in_hub)->toBeFalse();
});

it('has share url', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project]);

    expect($project->share_token)->not->toBeNull();
});

it('skips publish when no rendered video exists', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create([
        'user_id' => $user->id,
        'video_recording_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('publishToHub')
        ->assertSet('publishToHub', false);
});
