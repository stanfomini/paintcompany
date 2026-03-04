<?php

use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('video-recordings');
});

it('allows authenticated user to download a video', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    Storage::disk('video-recordings')->put($recording->video_path, 'fake-video-content');

    $this->actingAs($user)
        ->get(route('video-recordings.download', $recording))
        ->assertOk()
        ->assertDownload();
});

it('returns 404 for video without a playable path', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->create([
        'user_id' => $user->id,
        'status' => 'uploading',
    ]);

    $this->actingAs($user)
        ->get(route('video-recordings.download', $recording))
        ->assertNotFound();
});

it('redirects unauthenticated users to login', function () {
    $recording = VideoRecording::factory()->ready()->create();

    $this->get(route('video-recordings.download', $recording))
        ->assertRedirect(route('login'));
});

it('returns 404 when file does not exist on disk', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('video-recordings.download', $recording))
        ->assertNotFound();
});

it('uses the video title as the download filename', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'My Great Video',
    ]);

    Storage::disk('video-recordings')->put($recording->video_path, 'fake-video-content');

    $response = $this->actingAs($user)
        ->get(route('video-recordings.download', $recording));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))
        ->toContain('my-great-video.mp4');
});
