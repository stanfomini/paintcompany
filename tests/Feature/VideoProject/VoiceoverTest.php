<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can add a voiceover to a project via event', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->dispatch('voiceover-finalized', recordingId: $recording->id);

    expect($project->voiceovers()->count())->toBe(1);
    $vo = $project->voiceovers->first();
    expect($vo->video_recording_id)->toBe($recording->id);
    expect($vo->volume)->toBe(100);
    expect($vo->start_ms)->toBe(0);
});

it('sets sort order incrementally for voiceovers', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording1 = VideoRecording::factory()->ready()->create();
    $recording2 = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->dispatch('voiceover-finalized', recordingId: $recording1->id)
        ->dispatch('voiceover-finalized', recordingId: $recording2->id);

    $voiceovers = $project->voiceovers()->orderBy('sort_order')->get();
    expect($voiceovers)->toHaveCount(2);
    expect($voiceovers[0]->sort_order)->toBe(0);
    expect($voiceovers[1]->sort_order)->toBe(1);
});

it('links voiceover recording to project on finalize', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();

    expect($recording->recordable_type)->toBeNull();

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->dispatch('voiceover-finalized', recordingId: $recording->id);

    $recording->refresh();
    expect($recording->recordable_type)->toBe(VideoProject::class);
    expect($recording->recordable_id)->toBe($project->id);
});

it('can remove a voiceover', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();
    $voiceover = VideoProjectVoiceover::create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
        'volume' => 100,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeVoiceover', $voiceover->id);

    expect(VideoProjectVoiceover::count())->toBe(0);
});

it('can update voiceover volume', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();
    $voiceover = VideoProjectVoiceover::create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
        'volume' => 100,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'volume', 50);

    expect($voiceover->fresh()->volume)->toBe(50);
});

it('can update voiceover start position', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();
    $voiceover = VideoProjectVoiceover::create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
        'volume' => 100,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'start_ms', 5000);

    expect($voiceover->fresh()->start_ms)->toBe(5000);
});

it('cannot remove voiceover from another user project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $otherProject = VideoProject::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    $otherVoiceover = VideoProjectVoiceover::create([
        'video_project_id' => $otherProject->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
        'volume' => 100,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeVoiceover', $otherVoiceover->id);

    expect(VideoProjectVoiceover::count())->toBe(1);
});

it('rejects invalid voiceover field updates', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();
    $voiceover = VideoProjectVoiceover::create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
        'volume' => 100,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'video_recording_id', 999);

    expect($voiceover->fresh()->video_recording_id)->not->toBe(999);
});

it('includes voiceovers in project data', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $recording = VideoRecording::factory()->ready()->create();
    VideoProjectVoiceover::create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 1000,
        'volume' => 80,
        'sort_order' => 0,
    ]);

    $component = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project]);

    $data = $component->get('projectData');
    expect($data['voiceovers'])->toHaveCount(1);
    expect($data['voiceovers'][0]['volume'])->toBe(80);
    expect($data['voiceovers'][0]['start_ms'])->toBe(1000);
});

it('cannot update voiceover from another user project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $otherProject = VideoProject::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    $otherVoiceover = VideoProjectVoiceover::create([
        'video_project_id' => $otherProject->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
        'volume' => 100,
        'sort_order' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $otherVoiceover->id, 'volume', 25);

    expect($otherVoiceover->fresh()->volume)->toBe(100);
});
