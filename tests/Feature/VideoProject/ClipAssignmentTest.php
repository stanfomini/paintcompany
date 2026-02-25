<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectClip;
use App\Models\VideoProjectScene;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can assign a clip to a scene via selectMedia', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->set('activeSceneId', $scene->id)
        ->set('pendingSlot', 'main')
        ->call('selectMedia', 'video-recording', $recording->id);

    expect($scene->clips()->count())->toBe(1);
    $clip = $scene->clips()->first();
    expect($clip->slot)->toBe('main');
    expect($clip->source_type)->toBe('video-recording');
    expect($clip->source_id)->toBe($recording->id);
});

it('can remove a clip', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = VideoProjectClip::factory()->create(['video_project_scene_id' => $scene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeClip', $clip->id);

    expect(VideoProjectClip::count())->toBe(0);
});

it('cannot remove clip from another users project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $otherProject = VideoProject::factory()->create();
    $otherScene = VideoProjectScene::factory()->create(['video_project_id' => $otherProject->id]);
    $otherClip = VideoProjectClip::factory()->create(['video_project_scene_id' => $otherScene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeClip', $otherClip->id);

    expect(VideoProjectClip::count())->toBe(1);
});

it('can update clip volume', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = VideoProjectClip::factory()->create(['video_project_scene_id' => $scene->id, 'volume' => 100]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'volume', 50);

    expect($clip->fresh()->volume)->toBe(50);
});

it('can update clip fit mode', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = VideoProjectClip::factory()->create(['video_project_scene_id' => $scene->id, 'fit_mode' => 'cover']);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'fit_mode', 'contain');

    expect($clip->fresh()->fit_mode)->toBe('contain');
});

it('replaces clip in same slot', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording1 = VideoRecording::factory()->ready()->create();
    $recording2 = VideoRecording::factory()->ready()->create();

    $component = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->set('activeSceneId', $scene->id);

    // Assign first clip
    $component->set('pendingSlot', 'main')
        ->call('selectMedia', 'video-recording', $recording1->id);

    // Replace with second clip
    $component->set('pendingSlot', 'main')
        ->call('selectMedia', 'video-recording', $recording2->id);

    expect($scene->clips()->count())->toBe(1);
    expect($scene->clips()->first()->source_id)->toBe($recording2->id);
});

it('rejects invalid clip field updates', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = VideoProjectClip::factory()->create(['video_project_scene_id' => $scene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'source_id', 999);

    expect($clip->fresh()->source_id)->not->toBe(999);
});
