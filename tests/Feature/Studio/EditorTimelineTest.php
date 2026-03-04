<?php

use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectClip;
use App\Models\VideoProjectScene;
use App\Models\VideoProjectVoiceover;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('allows updating voiceover in_point_ms', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $voiceover = VideoProjectVoiceover::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'in_point_ms', 2000);

    expect($voiceover->fresh()->in_point_ms)->toBe(2000);
});

it('allows updating voiceover out_point_ms', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $voiceover = VideoProjectVoiceover::factory()->create(['video_project_id' => $project->id]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateVoiceover', $voiceover->id, 'out_point_ms', 5000);

    expect($voiceover->fresh()->out_point_ms)->toBe(5000);
});

it('includes voiceover trim fields in projectData', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'in_point_ms' => 1000,
        'out_point_ms' => 8000,
    ]);

    $component = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project]);

    $pd = $component->get('projectData');
    $vo = $pd['voiceovers'][0] ?? null;

    expect($vo)->not->toBeNull()
        ->and($vo['in_point_ms'])->toBe(1000)
        ->and($vo['out_point_ms'])->toBe(8000);
});

it('allows updating clip in_point_ms via updateClip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = \App\Models\VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'in_point_ms' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'in_point_ms', 3000);

    expect($clip->fresh()->in_point_ms)->toBe(3000);
});

it('allows updating clip out_point_ms via updateClip', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $clip = \App\Models\VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('updateClip', $clip->id, 'out_point_ms', 7000);

    expect($clip->fresh()->out_point_ms)->toBe(7000);
});

it('generates captions from voiceover source', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->withDuration(10000)->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'start_ms' => 0,
    ]);

    $this->mock(\App\Services\PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'segments' => [
                ['text' => 'Hello world', 'start' => 0.0, 'end' => 2.0],
                ['text' => 'This is a test', 'start' => 2.5, 'end' => 4.5],
            ],
        ]);
    });

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('generateCaptions', $scene->id, 'voiceover');

    $captions = $scene->overlays()->where('type', 'caption')->get();
    expect($captions)->toHaveCount(2)
        ->and($captions[0]->content)->toBe('Hello world')
        ->and($captions[1]->content)->toBe('This is a test');
});

it('generates captions from video source by default', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->withDuration(10000)->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    \App\Models\VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => 'video-recording',
        'source_id' => $recording->id,
    ]);

    $this->mock(\App\Services\PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'segments' => [
                ['text' => 'From video', 'start' => 0.0, 'end' => 3.0],
            ],
        ]);
    });

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('generateCaptions', $scene->id, 'video');

    $captions = $scene->overlays()->where('type', 'caption')->get();
    expect($captions)->toHaveCount(1)
        ->and($captions[0]->content)->toBe('From video');
});

// --- saveTimelineState tests ---

it('persists clip changes via saveTimelineState', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    $clip = VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => 'video-recording',
        'source_id' => $recording->id,
        'in_point_ms' => 0,
        'out_point_ms' => 5000,
        'start_ms' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('saveTimelineState', [
            [
                'id' => $clip->id,
                'scene_id' => $scene->id,
                'slot' => 'main',
                'source_type' => 'video-recording',
                'source_id' => $recording->id,
                'in_point_ms' => 1000,
                'out_point_ms' => 4000,
                'start_ms' => 500,
                'volume' => 80,
                'fit_mode' => 'cover',
                'sort_order' => 0,
            ],
        ], []);

    $clip->refresh();
    expect($clip->in_point_ms)->toBe(1000)
        ->and($clip->out_point_ms)->toBe(4000)
        ->and($clip->start_ms)->toBe(500)
        ->and($clip->volume)->toBe(80);
});

it('persists voiceover changes via saveTimelineState', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    $vo = VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
        'in_point_ms' => 0,
        'start_ms' => 0,
    ]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('saveTimelineState', [], [
            [
                'id' => $vo->id,
                'recording_id' => $recording->id,
                'in_point_ms' => 500,
                'out_point_ms' => 8000,
                'start_ms' => 1000,
                'volume' => 75,
                'sort_order' => 0,
            ],
        ]);

    $vo->refresh();
    expect($vo->in_point_ms)->toBe(500)
        ->and($vo->out_point_ms)->toBe(8000)
        ->and($vo->start_ms)->toBe(1000)
        ->and($vo->volume)->toBe(75);
});

it('deletes removed clips via saveTimelineState', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);

    $clip1 = VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);
    $clip2 = VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_id' => $recording->id,
        'sort_order' => 1,
    ]);

    // Save with only clip1 — clip2 should be deleted
    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('saveTimelineState', [
            [
                'id' => $clip1->id,
                'scene_id' => $scene->id,
                'slot' => 'main',
                'source_type' => 'video-recording',
                'source_id' => $recording->id,
                'in_point_ms' => 0,
                'out_point_ms' => 5000,
                'start_ms' => 0,
                'volume' => 100,
                'fit_mode' => 'cover',
                'sort_order' => 0,
            ],
        ], []);

    expect(VideoProjectClip::find($clip1->id))->not->toBeNull()
        ->and(VideoProjectClip::find($clip2->id))->toBeNull();
});

it('deletes removed voiceovers via saveTimelineState', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);

    $vo1 = VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
    ]);
    $vo2 = VideoProjectVoiceover::factory()->create([
        'video_project_id' => $project->id,
        'video_recording_id' => $recording->id,
    ]);

    // Save with only vo1 — vo2 should be deleted
    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('saveTimelineState', [], [
            [
                'id' => $vo1->id,
                'recording_id' => $recording->id,
                'in_point_ms' => 0,
                'out_point_ms' => null,
                'start_ms' => 0,
                'volume' => 100,
                'sort_order' => 0,
            ],
        ]);

    expect(VideoProjectVoiceover::find($vo1->id))->not->toBeNull()
        ->and(VideoProjectVoiceover::find($vo2->id))->toBeNull();
});

it('creates new clips from temp IDs via saveTimelineState', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);

    Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project])
        ->call('saveTimelineState', [
            [
                'id' => 'temp_123456_1',
                'scene_id' => $scene->id,
                'slot' => 'main',
                'source_type' => 'video-recording',
                'source_id' => $recording->id,
                'in_point_ms' => 0,
                'out_point_ms' => 3000,
                'start_ms' => 0,
                'volume' => 100,
                'fit_mode' => 'cover',
                'sort_order' => 0,
            ],
            [
                'id' => 'temp_123456_2',
                'scene_id' => $scene->id,
                'slot' => 'main_split',
                'source_type' => 'video-recording',
                'source_id' => $recording->id,
                'in_point_ms' => 3000,
                'out_point_ms' => 6000,
                'start_ms' => 3000,
                'volume' => 100,
                'fit_mode' => 'cover',
                'sort_order' => 1,
            ],
        ], []);

    $clips = VideoProjectClip::where('video_project_scene_id', $scene->id)->orderBy('sort_order')->get();
    expect($clips)->toHaveCount(2)
        ->and($clips[0]->in_point_ms)->toBe(0)
        ->and($clips[0]->out_point_ms)->toBe(3000)
        ->and($clips[1]->in_point_ms)->toBe(3000)
        ->and($clips[1]->out_point_ms)->toBe(6000)
        ->and($clips[1]->start_ms)->toBe(3000);
});

it('includes clip start_ms in projectData', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = VideoProjectScene::factory()->create(['video_project_id' => $project->id]);
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 10]);
    VideoProjectClip::factory()->create([
        'video_project_scene_id' => $scene->id,
        'source_type' => 'video-recording',
        'source_id' => $recording->id,
        'start_ms' => 2500,
    ]);

    $component = Livewire::actingAs($user)
        ->test('studio.editor', ['videoProject' => $project]);

    $pd = $component->get('projectData');
    $clip = $pd['scenes'][0]['clips'][0] ?? null;

    expect($clip)->not->toBeNull()
        ->and($clip['start_ms'])->toBe(2500);
});
