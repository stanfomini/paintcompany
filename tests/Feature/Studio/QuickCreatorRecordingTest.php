<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('adds a recorded clip to the project', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->create(['user_id' => $user->id, 'status' => 'ready']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addRecordedClip', $recording->id);

    $clip = $project->scenes()->first()->clips()->first();
    expect($clip)->not->toBeNull();
    expect($clip->source_type)->toBe(VideoRecording::class);
    expect($clip->source_id)->toBe($recording->id);
});

it('adds multiple recorded segments as separate clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $rec1 = VideoRecording::factory()->create(['user_id' => $user->id, 'status' => 'ready']);
    $rec2 = VideoRecording::factory()->create(['user_id' => $user->id, 'status' => 'ready']);

    Livewire::actingAs($user)
        ->test('studio.quick-creator', ['videoProject' => $project])
        ->call('addRecordedClip', $rec1->id)
        ->call('addRecordedClip', $rec2->id);

    expect($project->scenes()->first()->clips()->count())->toBe(2);
});
