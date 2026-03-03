<?php

use App\Models\PropertyPhoto;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use App\Services\PythonBridgeService;
use App\Services\StudioAiService;

it('generates captions for video clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'text' => 'Hello, welcome to our walkthrough.',
            'segments' => [
                ['start' => 0.0, 'end' => 1.5, 'text' => 'Hello,'],
                ['start' => 1.5, 'end' => 3.0, 'text' => 'welcome to our walkthrough.'],
            ],
        ]);
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(2)
        ->and($captions[0]['text'])->toBe('Hello,')
        ->and($captions[0]['start'])->toBe(0.0)
        ->and($captions[0]['end'])->toBe(1.5)
        ->and($captions[0]['scene_id'])->toBe($scene->id)
        ->and($captions[1]['text'])->toBe('welcome to our walkthrough.')
        ->and($scene->overlays()->count())->toBe(2)
        ->and($scene->overlays()->first()->type)->toBe('caption');
});

it('skips photo clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $photo = PropertyPhoto::factory()->create();
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => PropertyPhoto::class,
        'source_id' => $photo->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldNotReceive('transcribeAudio');
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(0)
        ->and($scene->overlays()->count())->toBe(0);
});

it('returns empty array when python is disabled', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(false);
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(0);
});

it('handles transcription errors gracefully', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('transcribeAudio')->once()->andThrow(new \Exception('Whisper failed'));
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(0)
        ->and($scene->overlays()->count())->toBe(0);
});

it('skips recordings that are not ready', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $recording = VideoRecording::factory()->processing()->create(['user_id' => $user->id]);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldNotReceive('transcribeAudio');
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(0);
});

it('skips empty caption segments', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'text' => 'Some text',
            'segments' => [
                ['start' => 0.0, 'end' => 1.0, 'text' => '  '],
                ['start' => 1.0, 'end' => 2.0, 'text' => ''],
                ['start' => 2.0, 'end' => 3.0, 'text' => 'Actual content'],
            ],
        ]);
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(1)
        ->and($captions[0]['text'])->toBe('Actual content')
        ->and($scene->overlays()->count())->toBe(1);
});

it('processes multiple scenes with video clips', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene1 = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $scene2 = $project->scenes()->create(['sort_order' => 1, 'name' => 'Scene 2', 'layout' => 'solo']);

    $recording1 = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $recording2 = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    $scene1->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording1->id,
        'sort_order' => 0,
    ]);
    $scene2->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording2->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('transcribeAudio')->twice()->andReturn([
            'segments' => [
                ['start' => 0.0, 'end' => 2.0, 'text' => 'Scene caption'],
            ],
        ]);
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(2)
        ->and($scene1->overlays()->count())->toBe(1)
        ->and($scene2->overlays()->count())->toBe(1);
});

it('skips response without segments key', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id]);
    $scene = $project->scenes()->create(['sort_order' => 0, 'name' => 'Scene 1', 'layout' => 'solo']);
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $scene->clips()->create([
        'slot' => 'main',
        'source_type' => VideoRecording::class,
        'source_id' => $recording->id,
        'sort_order' => 0,
    ]);

    $this->mock(PythonBridgeService::class, function ($mock) {
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('transcribeAudio')->once()->andReturn([
            'text' => 'Some text but no segments',
        ]);
    });

    $service = new StudioAiService($user);
    $captions = $service->generateCaptions($project);

    expect($captions)->toHaveCount(0)
        ->and($scene->overlays()->count())->toBe(0);
});
