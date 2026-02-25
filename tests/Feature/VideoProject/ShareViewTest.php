<?php

use App\Models\VideoProject;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can view a shared rendered project', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $project = VideoProject::factory()->rendered()->create([
        'visibility' => 'public',
        'video_recording_id' => $recording->id,
    ]);

    $this->get("/studio/share/{$project->share_token}")
        ->assertSuccessful()
        ->assertSee($project->title);
});

it('returns 404 for draft projects', function () {
    $project = VideoProject::factory()->create([
        'visibility' => 'public',
        'status' => 'draft',
    ]);

    $this->get("/studio/share/{$project->share_token}")
        ->assertNotFound();
});

it('returns 404 for internal visibility', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $project = VideoProject::factory()->rendered()->create([
        'visibility' => 'internal',
        'video_recording_id' => $recording->id,
    ]);

    $this->get("/studio/share/{$project->share_token}")
        ->assertNotFound();
});

it('allows unlisted projects to be viewed', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $project = VideoProject::factory()->rendered()->create([
        'visibility' => 'unlisted',
        'video_recording_id' => $recording->id,
    ]);

    $this->get("/studio/share/{$project->share_token}")
        ->assertSuccessful();
});

it('returns 404 for invalid token', function () {
    $this->get('/studio/share/invalid-token-here')
        ->assertNotFound();
});

it('returns 404 for rendering projects', function () {
    $project = VideoProject::factory()->rendering()->create([
        'visibility' => 'public',
    ]);

    $this->get("/studio/share/{$project->share_token}")
        ->assertNotFound();
});

it('returns 404 for failed projects', function () {
    $project = VideoProject::factory()->failed()->create([
        'visibility' => 'public',
    ]);

    $this->get("/studio/share/{$project->share_token}")
        ->assertNotFound();
});
