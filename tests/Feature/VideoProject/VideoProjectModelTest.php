<?php

use App\Models\RecordingInvite;
use App\Models\RemotionBuildRequest;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectClip;
use App\Models\VideoProjectOverlay;
use App\Models\VideoProjectScene;
use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// VideoProject model tests
it('can create a video project with factory', function () {
    $project = VideoProject::factory()->create();
    expect($project)->toBeInstanceOf(VideoProject::class)
        ->and($project->status)->toBe('draft')
        ->and($project->resolution_width)->toBe(1920)
        ->and($project->resolution_height)->toBe(1080)
        ->and($project->fps)->toBe(30)
        ->and($project->share_token)->toHaveLength(32);
});

it('auto-generates share token on creation', function () {
    $project = VideoProject::factory()->create(['share_token' => null]);
    expect($project->share_token)->not->toBeNull()->toHaveLength(32);
});

it('does not overwrite explicit share token', function () {
    $project = VideoProject::factory()->create(['share_token' => 'my-custom-token-12345678901234']);
    expect($project->share_token)->toBe('my-custom-token-12345678901234');
});

it('has user relationship', function () {
    $user = User::factory()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    expect($project->user->id)->toBe($user->id);
});

it('has scenes relationship ordered by sort_order', function () {
    $project = VideoProject::factory()->create();
    VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 2, 'name' => 'Second']);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 0, 'name' => 'First']);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'sort_order' => 1, 'name' => 'Middle']);

    $names = $project->scenes->pluck('name')->toArray();
    expect($names)->toBe(['First', 'Middle', 'Second']);
});

it('has voiceovers relationship', function () {
    $project = VideoProject::factory()->create();
    VideoProjectVoiceover::factory()->create(['video_project_id' => $project->id]);
    expect($project->voiceovers)->toHaveCount(1);
});

it('has rendered video relationship', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $project = VideoProject::factory()->create(['video_recording_id' => $recording->id]);
    expect($project->renderedVideo->id)->toBe($recording->id);
});

it('computes duration from scenes', function () {
    $project = VideoProject::factory()->create();
    VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'duration_ms' => 5000]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id, 'duration_ms' => 10000]);

    expect($project->getComputedDurationMs())->toBe(15000);
});

it('returns zero duration with no scenes', function () {
    $project = VideoProject::factory()->create();
    expect($project->getComputedDurationMs())->toBe(0);
});

it('has status helper methods', function () {
    expect(VideoProject::factory()->create(['status' => 'draft'])->isDraft())->toBeTrue();
    expect(VideoProject::factory()->create(['status' => 'rendering'])->isRendering())->toBeTrue();
    expect(VideoProject::factory()->create(['status' => 'rendered'])->isRendered())->toBeTrue();
    expect(VideoProject::factory()->create(['status' => 'failed'])->isFailed())->toBeTrue();
});

it('has factory states', function () {
    expect(VideoProject::factory()->rendering()->create()->status)->toBe('rendering');
    expect(VideoProject::factory()->failed()->create()->status)->toBe('failed');
    $rendered = VideoProject::factory()->rendered()->create();
    expect($rendered->status)->toBe('rendered')
        ->and($rendered->video_recording_id)->not->toBeNull();
});

// VideoProjectScene model tests
it('can create a scene with factory', function () {
    $scene = VideoProjectScene::factory()->create();
    expect($scene->layout)->toBe('solo')
        ->and($scene->transition_type)->toBe('cut');
});

it('scene has clips relationship', function () {
    $scene = VideoProjectScene::factory()->create();
    VideoProjectClip::factory()->create(['video_project_scene_id' => $scene->id]);
    expect($scene->clips)->toHaveCount(1);
});

it('scene has overlays relationship', function () {
    $scene = VideoProjectScene::factory()->create();
    VideoProjectOverlay::factory()->create(['video_project_scene_id' => $scene->id]);
    expect($scene->overlays)->toHaveCount(1);
});

it('scene returns explicit duration', function () {
    $scene = VideoProjectScene::factory()->withDuration(8000)->create();
    expect($scene->getEffectiveDurationMs())->toBe(8000);
});

it('scene returns fallback duration with no clips', function () {
    $scene = VideoProjectScene::factory()->create();
    expect($scene->getEffectiveDurationMs())->toBe(5000);
});

it('scene has factory states', function () {
    expect(VideoProjectScene::factory()->split()->create()->layout)->toBe('split');
    expect(VideoProjectScene::factory()->pip()->create()->layout)->toBe('pip');
});

// VideoProjectClip model tests
it('can create a clip with factory', function () {
    $clip = VideoProjectClip::factory()->create();
    expect($clip->slot)->toBe('main')
        ->and($clip->volume)->toBe(100)
        ->and($clip->fit_mode)->toBe('cover');
});

it('clip has source morph relationship', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $clip = VideoProjectClip::factory()->create([
        'source_type' => 'video-recording',
        'source_id' => $recording->id,
    ]);
    expect($clip->source)->toBeInstanceOf(VideoRecording::class)
        ->and($clip->source->id)->toBe($recording->id);
});

it('clip has factory states', function () {
    expect(VideoProjectClip::factory()->secondary()->create()->slot)->toBe('secondary');
    expect(VideoProjectClip::factory()->pip()->create()->slot)->toBe('pip');
    expect(VideoProjectClip::factory()->muted()->create()->volume)->toBe(0);
});

// VideoProjectOverlay model tests
it('can create an overlay with factory', function () {
    $overlay = VideoProjectOverlay::factory()->create();
    expect($overlay->type)->toBe('text');
});

it('overlay has type helpers', function () {
    expect(VideoProjectOverlay::factory()->create(['type' => 'text'])->isText())->toBeTrue();
    expect(VideoProjectOverlay::factory()->create(['type' => 'caption'])->isCaption())->toBeTrue();
    expect(VideoProjectOverlay::factory()->create(['type' => 'image'])->isImage())->toBeTrue();
});

it('overlay has caption factory state', function () {
    $overlay = VideoProjectOverlay::factory()->caption()->create();
    expect($overlay->type)->toBe('caption')
        ->and($overlay->position_y)->toBeGreaterThan(0.8)
        ->and($overlay->style)->toBeArray()
        ->and($overlay->style['font_size'])->toBe(24);
});

// VideoProjectVoiceover model tests
it('can create a voiceover with factory', function () {
    $voiceover = VideoProjectVoiceover::factory()->create();
    expect($voiceover->volume)->toBe(100)
        ->and($voiceover->recording)->toBeInstanceOf(VideoRecording::class);
});

// RecordingInvite model tests
it('can create a recording invite', function () {
    $invite = RecordingInvite::factory()->create();
    expect($invite->status)->toBe('pending')
        ->and($invite->token)->toHaveLength(64)
        ->and($invite->max_duration_seconds)->toBe(120);
});

it('invite auto-generates token on creation', function () {
    $invite = RecordingInvite::factory()->create(['token' => null]);
    expect($invite->token)->not->toBeNull()->toHaveLength(64);
});

it('invite has status helpers', function () {
    $pending = RecordingInvite::factory()->create(['expires_at' => now()->addDay()]);
    expect($pending->isPending())->toBeTrue()
        ->and($pending->isExpired())->toBeFalse()
        ->and($pending->isCompleted())->toBeFalse();

    $expired = RecordingInvite::factory()->expired()->create();
    expect($expired->isExpired())->toBeTrue();

    $completed = RecordingInvite::factory()->completed()->create();
    expect($completed->isCompleted())->toBeTrue();
});

it('invite has active scope', function () {
    RecordingInvite::factory()->create(['expires_at' => now()->addDay(), 'status' => 'pending']);
    RecordingInvite::factory()->expired()->create();
    RecordingInvite::factory()->completed()->create();

    expect(RecordingInvite::active()->count())->toBe(1);
});

// RemotionBuildRequest model tests
it('can create a remotion build request', function () {
    $request = RemotionBuildRequest::factory()->create();
    expect($request->status)->toBe('pending')
        ->and($request->prompt)->not->toBeNull();
});

it('remotion build request has status lifecycle', function () {
    $request = RemotionBuildRequest::factory()->create();
    expect($request->isActive())->toBeTrue();

    $request->markBuilding();
    expect($request->status)->toBe('building')
        ->and($request->started_at)->not->toBeNull()
        ->and($request->isActive())->toBeTrue();

    $recording = VideoRecording::factory()->ready()->create();
    $request->markCompleted($recording->id);
    expect($request->status)->toBe('completed')
        ->and($request->video_recording_id)->toBe($recording->id)
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->isActive())->toBeFalse();
});

it('remotion build request can be marked failed', function () {
    $request = RemotionBuildRequest::factory()->create();
    $request->markFailed('Build timed out');
    expect($request->status)->toBe('failed')
        ->and($request->error_message)->toBe('Build timed out')
        ->and($request->completed_at)->not->toBeNull();
});

// Cascade delete tests
it('cascades delete from project to scenes', function () {
    $project = VideoProject::factory()->create();
    VideoProjectScene::factory()->count(3)->create(['video_project_id' => $project->id]);
    expect(VideoProjectScene::where('video_project_id', $project->id)->count())->toBe(3);

    $project->delete();
    expect(VideoProjectScene::where('video_project_id', $project->id)->count())->toBe(0);
});

it('cascades delete from scene to clips and overlays', function () {
    $scene = VideoProjectScene::factory()->create();
    VideoProjectClip::factory()->count(2)->create(['video_project_scene_id' => $scene->id]);
    VideoProjectOverlay::factory()->count(2)->create(['video_project_scene_id' => $scene->id]);

    $scene->delete();
    expect(VideoProjectClip::where('video_project_scene_id', $scene->id)->count())->toBe(0);
    expect(VideoProjectOverlay::where('video_project_scene_id', $scene->id)->count())->toBe(0);
});
