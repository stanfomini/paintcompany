<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can link rendered video to project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'status' => 'draft']);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 45]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('linkRenderedVideo', $recording->id);

    $project->refresh();
    expect($project->status)->toBe('rendered')
        ->and($project->video_recording_id)->toBe($recording->id)
        ->and((int) $project->duration_seconds)->toBe(45);
});

it('can update project visibility', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVisibility', 'public');

    expect($project->fresh()->visibility)->toBe('public');
});

it('rejects invalid visibility values', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'visibility' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVisibility', 'hacked');

    expect($project->fresh()->visibility)->toBe('internal');
});

it('accepts all valid visibility values', function (string $visibility) {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVisibility', $visibility);

    expect($project->fresh()->visibility)->toBe($visibility);
})->with(['public', 'internal', 'unlisted']);

it('generates share url with project token', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    $result = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('getShareUrl');

    expect($project->share_token)->not->toBeNull();
});
