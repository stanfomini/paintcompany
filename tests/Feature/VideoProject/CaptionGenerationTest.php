<?php

use App\Jobs\GenerateSceneCaptionsJob;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectClip;
use App\Models\VideoProjectOverlay;
use App\Models\VideoProjectScene;
use App\Models\VideoRecording;
use App\Services\CaptionGenerationService;
use App\Services\PythonBridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('dispatches caption generation job', function () {
    Queue::fake();

    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('generateCaptions', $scene->id);

    Queue::assertPushed(GenerateSceneCaptionsJob::class, fn ($job) => $job->scene->id === $scene->id);
});

it('does not dispatch job for another users scene', function () {
    Queue::fake();

    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $otherProject = VideoProject::factory()->create();
    $otherScene = VideoProjectScene::factory()->create(['video_project_id' => $otherProject->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('generateCaptions', $otherScene->id);

    Queue::assertNotPushed(GenerateSceneCaptionsJob::class);
});

it('generates caption segments from whisper segments', function () {
    $scene = VideoProjectScene::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'in_point_ms' => 0,
    ]);

    $mockPython = $this->mock(PythonBridgeService::class);
    $mockPython->shouldReceive('transcribeAudio')
        ->once()
        ->andReturn([
            'segments' => [
                ['text' => 'Hello world', 'start' => 0.0, 'end' => 1.5],
                ['text' => 'This is a test', 'start' => 1.5, 'end' => 3.0],
            ],
        ]);

    $service = app(CaptionGenerationService::class);
    $segments = $service->generateForScene($scene);

    expect($segments)->toHaveCount(2);
    expect($segments[0]['text'])->toBe('Hello world');
    expect($segments[0]['start_ms'])->toBe(0);
    expect($segments[0]['end_ms'])->toBe(1500);
    expect($segments[1]['text'])->toBe('This is a test');
    expect($segments[1]['start_ms'])->toBe(1500);
    expect($segments[1]['end_ms'])->toBe(3000);
});

it('offsets caption timestamps by clip in_point_ms', function () {
    $scene = VideoProjectScene::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'in_point_ms' => 5000,
    ]);

    $mockPython = $this->mock(PythonBridgeService::class);
    $mockPython->shouldReceive('transcribeAudio')
        ->once()
        ->andReturn([
            'segments' => [
                ['text' => 'Offset test', 'start' => 1.0, 'end' => 2.0],
            ],
        ]);

    $service = app(CaptionGenerationService::class);
    $segments = $service->generateForScene($scene);

    expect($segments)->toHaveCount(1);
    expect($segments[0]['start_ms'])->toBe(6000);
    expect($segments[0]['end_ms'])->toBe(7000);
});

it('creates overlay records from job', function () {
    $scene = VideoProjectScene::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'in_point_ms' => 0,
    ]);

    $mockPython = $this->mock(PythonBridgeService::class);
    $mockPython->shouldReceive('transcribeAudio')
        ->once()
        ->andReturn([
            'segments' => [
                ['text' => 'Hello', 'start' => 0.0, 'end' => 1.0],
            ],
        ]);

    (new GenerateSceneCaptionsJob($scene))->handle(app(CaptionGenerationService::class));

    expect($scene->overlays()->count())->toBe(1);
    $overlay = $scene->overlays()->first();
    expect($overlay->type)->toBe('caption');
    expect($overlay->content)->toBe('Hello');
    expect((float) $overlay->position_y)->toEqualWithDelta(0.85, 0.01);
    expect($overlay->start_ms)->toBe(0);
    expect($overlay->end_ms)->toBe(1000);
    expect($overlay->style)->toBeArray();
    expect($overlay->style['font_size'])->toBe(24);
});

it('clears existing captions before regenerating', function () {
    $scene = VideoProjectScene::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
    ]);

    VideoProjectOverlay::factory()->caption()->create(['video_project_scene_id' => $scene->id]);

    $mockPython = $this->mock(PythonBridgeService::class);
    $mockPython->shouldReceive('transcribeAudio')
        ->once()
        ->andReturn([
            'segments' => [
                ['text' => 'New caption', 'start' => 0.0, 'end' => 1.0],
            ],
        ]);

    (new GenerateSceneCaptionsJob($scene))->handle(app(CaptionGenerationService::class));

    expect($scene->overlays()->where('type', 'caption')->count())->toBe(1);
    expect($scene->overlays()->first()->content)->toBe('New caption');
});

it('can clear captions for a scene', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    VideoProjectOverlay::factory()->caption()->create(['video_project_scene_id' => $scene->id]);
    VideoProjectOverlay::factory()->create(['video_project_scene_id' => $scene->id, 'type' => 'text']);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('clearCaptions', $scene->id);

    expect($scene->overlays()->where('type', 'caption')->count())->toBe(0);
    expect($scene->overlays()->where('type', 'text')->count())->toBe(1);
});

it('handles scenes with no video clips gracefully', function () {
    $scene = VideoProjectScene::factory()->create();

    $service = app(CaptionGenerationService::class);
    $segments = $service->generateForScene($scene);

    expect($segments)->toBeEmpty();
});

it('handles transcription errors gracefully', function () {
    $scene = VideoProjectScene::factory()->create();
    $recording = VideoRecording::factory()->ready()->create();
    VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
    ]);

    $mockPython = $this->mock(PythonBridgeService::class);
    $mockPython->shouldReceive('transcribeAudio')
        ->once()
        ->andThrow(new \RuntimeException('Service unavailable'));

    $service = app(CaptionGenerationService::class);
    $segments = $service->generateForScene($scene);

    expect($segments)->toBeEmpty();
});

it('skips non-video-recording clips', function () {
    $scene = VideoProjectScene::factory()->create();
    VideoProjectClip::factory()->fromPhoto()->create([
        'video_project_scene_id' => $scene->id,
    ]);

    $mockPython = $this->mock(PythonBridgeService::class);
    $mockPython->shouldNotReceive('transcribeAudio');

    $service = app(CaptionGenerationService::class);
    $segments = $service->generateForScene($scene);

    expect($segments)->toBeEmpty();
});

it('cannot clear captions for another users scene', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $otherProject = VideoProject::factory()->create();
    $otherScene = VideoProjectScene::factory()->create(['video_project_id' => $otherProject->id]);
    $caption = VideoProjectOverlay::factory()->caption()->create(['video_project_scene_id' => $otherScene->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('clearCaptions', $otherScene->id);

    expect(VideoProjectOverlay::where('id', $caption->id)->exists())->toBeTrue();
});
