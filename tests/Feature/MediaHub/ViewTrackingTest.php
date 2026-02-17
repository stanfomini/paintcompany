<?php

use App\Models\User;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use App\Models\VideoView;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('creates a video view record', function () {
    $video = VideoRecording::factory()->ready()->create();

    $this->postJson('/api/video-views', [
        'video_id' => $video->id,
        'duration' => 30,
        'percentage' => 50,
        'source' => 'channel',
        'session_id' => 'test-session-123',
    ])->assertOk()
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('video_views', [
        'video_recording_id' => $video->id,
        'watch_duration_seconds' => 30,
        'watch_percentage' => 50,
        'source' => 'channel',
    ]);
});

it('validates required fields for video views', function () {
    $this->postJson('/api/video-views', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['video_id', 'duration', 'percentage', 'source', 'session_id']);
});

it('deduplicates views within 30 minute session', function () {
    $video = VideoRecording::factory()->ready()->create();

    // First view
    $this->postJson('/api/video-views', [
        'video_id' => $video->id,
        'duration' => 15,
        'percentage' => 25,
        'source' => 'channel',
        'session_id' => 'session-abc',
    ])->assertOk();

    // Second view from same IP within 30 minutes - should update, not create
    $this->postJson('/api/video-views', [
        'video_id' => $video->id,
        'duration' => 60,
        'percentage' => 75,
        'source' => 'channel',
        'session_id' => 'session-abc',
    ])->assertOk();

    expect(VideoView::where('video_recording_id', $video->id)->count())->toBe(1);
    expect(VideoView::where('video_recording_id', $video->id)->first())
        ->watch_duration_seconds->toBe(60)
        ->watch_percentage->toBe(75);
});

it('works for anonymous users', function () {
    $video = VideoRecording::factory()->ready()->create();

    $this->postJson('/api/video-views', [
        'video_id' => $video->id,
        'duration' => 10,
        'percentage' => 20,
        'source' => 'link',
        'session_id' => 'anon-session',
    ])->assertOk();

    $this->assertDatabaseHas('video_views', [
        'video_recording_id' => $video->id,
        'viewer_id' => null,
    ]);
});

it('records authenticated viewer id', function () {
    $user = User::factory()->internal()->create();
    $video = VideoRecording::factory()->ready()->create();

    $this->actingAs($user)->postJson('/api/video-views', [
        'video_id' => $video->id,
        'duration' => 20,
        'percentage' => 40,
        'source' => 'channel',
        'session_id' => 'auth-session',
    ])->assertOk();

    $this->assertDatabaseHas('video_views', [
        'video_recording_id' => $video->id,
        'viewer_id' => $user->id,
    ]);
});

it('email pixel returns gif and marks opened', function () {
    $email = VideoEmail::factory()->create(['opened_at' => null]);

    $response = $this->get("/api/video-emails/{$email->id}/pixel");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/gif');

    $email->refresh();
    expect($email->opened_at)->not->toBeNull();
});

it('email pixel does not re-mark if already opened', function () {
    $openedAt = now()->subHour();
    $email = VideoEmail::factory()->create(['opened_at' => $openedAt]);

    $this->get("/api/video-emails/{$email->id}/pixel")->assertOk();

    $email->refresh();
    expect($email->opened_at->timestamp)->toBe($openedAt->timestamp);
});

it('email click redirects and marks clicked', function () {
    $video = VideoRecording::factory()->ready()->create();
    $email = VideoEmail::factory()->create([
        'video_recording_id' => $video->id,
        'clicked_at' => null,
    ]);

    $response = $this->get("/api/video-emails/{$email->id}/click");

    $response->assertRedirect();

    $email->refresh();
    expect($email->clicked_at)->not->toBeNull();
});

it('email click does not re-mark if already clicked', function () {
    $clickedAt = now()->subHour();
    $video = VideoRecording::factory()->ready()->create();
    $email = VideoEmail::factory()->create([
        'video_recording_id' => $video->id,
        'clicked_at' => $clickedAt,
    ]);

    $this->get("/api/video-emails/{$email->id}/click")->assertRedirect();

    $email->refresh();
    expect($email->clicked_at->timestamp)->toBe($clickedAt->timestamp);
});
