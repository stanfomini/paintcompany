<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectOverlay;
use App\Models\VideoProjectScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can add a text overlay to a scene', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->set('activeSceneId', $scene->id)
        ->call('addOverlay', 'text', 'Hello World');

    expect($scene->overlays()->count())->toBe(1);
    $overlay = $scene->overlays()->first();
    expect($overlay->type)->toBe('text');
    expect($overlay->content)->toBe('Hello World');
    expect($overlay->style)->toBeArray();
});

it('can add overlay with default content', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->set('activeSceneId', $scene->id)
        ->call('addOverlay');

    expect($scene->overlays()->count())->toBe(1);
});

it('does not add overlay without active scene', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->set('activeSceneId', null)
        ->call('addOverlay');

    expect(VideoProjectOverlay::count())->toBe(0);
});

it('can update overlay content', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $overlay = VideoProjectOverlay::factory()->create([
        'video_project_scene_id' => $scene->id,
        'content' => 'Old Text',
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateOverlay', $overlay->id, ['content' => 'New Text']);

    expect($overlay->fresh()->content)->toBe('New Text');
});

it('can update overlay position', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $overlay = VideoProjectOverlay::factory()->create(['video_project_scene_id' => $scene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateOverlay', $overlay->id, ['position_x' => 0.25, 'position_y' => 0.75]);

    $overlay->refresh();
    expect((float) $overlay->position_x)->toBe(0.25);
    expect((float) $overlay->position_y)->toBe(0.75);
});

it('can remove an overlay', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $overlay = VideoProjectOverlay::factory()->create(['video_project_scene_id' => $scene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeOverlay', $overlay->id);

    expect(VideoProjectOverlay::count())->toBe(0);
});

it('cannot remove overlay from another users project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $otherProject = VideoProject::factory()->create();
    $otherScene = VideoProjectScene::factory()->create(['video_project_id' => $otherProject->id]);
    $otherOverlay = VideoProjectOverlay::factory()->create(['video_project_scene_id' => $otherScene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('removeOverlay', $otherOverlay->id);

    expect(VideoProjectOverlay::count())->toBe(1);
});

it('can link rendered video to project', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'status' => 'draft']);
    $recording = \App\Models\VideoRecording::factory()->ready()->create(['duration_seconds' => 45]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('linkRenderedVideo', $recording->id);

    $project->refresh();
    expect($project->status)->toBe('rendered');
    expect($project->video_recording_id)->toBe($recording->id);
    expect((int) $project->duration_seconds)->toBe(45);
});
