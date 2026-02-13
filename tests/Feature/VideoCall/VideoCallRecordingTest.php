<?php

use App\Models\User;
use App\Models\VideoCall;
use App\Services\PythonBridgeService;
use App\Services\VideoRecordingService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;

// ── Recording Controller Tests ─────────────────────────────────────────

it('can start a recording for an active video call', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $videoCall = VideoCall::factory()->active()->create();

    $mockResponse = new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
        'status' => 'recording',
        'recording_id' => "vc-{$videoCall->id}",
        'output_dir' => "/tmp/hls-recordings/vc-{$videoCall->id}",
    ])));

    $this->mock(PythonBridgeService::class, function ($mock) use ($mockResponse) {
        $mock->shouldReceive('postRaw')
            ->once()
            ->andReturn($mockResponse);
    });

    $this->actingAs($user)
        ->postJson(route('video-call.recording.start', $videoCall))
        ->assertSuccessful()
        ->assertJsonStructure(['status', 'recording_id']);
});

it('prevents starting recording when one already exists', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $videoCall = VideoCall::factory()->withRecording()->create();

    $this->actingAs($user)
        ->postJson(route('video-call.recording.start', $videoCall))
        ->assertStatus(422)
        ->assertJsonPath('error', 'This call already has a recording.');
});

it('can stop a recording and store HLS files', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $videoCall = VideoCall::factory()->active()->create();

    $recordingId = "vc-{$videoCall->id}";

    $stopResponse = new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
        'status' => 'complete',
        'recording_id' => $recordingId,
        'manifest_path' => "/tmp/hls-recordings/{$recordingId}/playlist.m3u8",
        'output_dir' => "/tmp/hls-recordings/{$recordingId}",
        'files' => ['playlist.m3u8', 'segment_0000.ts'],
    ])));

    $cleanupResponse = new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
        'status' => 'cleaned',
    ])));

    $this->mock(PythonBridgeService::class, function ($mock) use ($stopResponse, $cleanupResponse) {
        $mock->shouldReceive('postRaw')
            ->andReturn($stopResponse, $cleanupResponse);
    });

    $this->mock(VideoRecordingService::class, function ($mock) {
        $mock->shouldReceive('storeFromPythonOutput')
            ->once();
    });

    $this->actingAs($user)
        ->postJson(route('video-call.recording.stop', $videoCall), [
            'recording_id' => $recordingId,
        ])
        ->assertSuccessful()
        ->assertJsonPath('status', 'complete');
});

it('returns 404 when no recording exists for playback', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $videoCall = VideoCall::factory()->active()->create();

    $this->actingAs($user)
        ->getJson(route('video-call.recording.playback', $videoCall))
        ->assertNotFound();
});

it('returns playback URL for recorded call', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $videoCall = VideoCall::factory()->withRecording()->create();

    $this->mock(VideoRecordingService::class, function ($mock) {
        $mock->shouldReceive('getPlaybackUrl')
            ->once()
            ->andReturn('https://example.com/recording.m3u8');
    });

    $this->actingAs($user)
        ->getJson(route('video-call.recording.playback', $videoCall))
        ->assertSuccessful()
        ->assertJsonPath('url', 'https://example.com/recording.m3u8');
});

// ── VideoRecordingService Tests ────────────────────────────────────────

it('stores HLS files from Python output directory', function () {
    Storage::fake('video-recordings');

    $videoCall = VideoCall::factory()->active()->create();

    // Create a fake output directory with HLS files
    $tmpDir = sys_get_temp_dir().'/test-hls-'.uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir.'/playlist.m3u8', '#EXTM3U');
    file_put_contents($tmpDir.'/segment_0000.ts', 'fake-ts-data');

    $service = new VideoRecordingService;
    $service->storeFromPythonOutput($videoCall, $tmpDir);

    $videoCall->refresh();

    $expectedPath = "recordings/{$videoCall->job_id}/{$videoCall->id}/playlist.m3u8";
    expect($videoCall->recording_path)->toBe($expectedPath);
    expect($videoCall->recording_disk)->toBe('video-recordings');

    Storage::disk('video-recordings')->assertExists($expectedPath);
    Storage::disk('video-recordings')->assertExists(
        "recordings/{$videoCall->job_id}/{$videoCall->id}/segment_0000.ts"
    );

    // Cleanup
    @unlink($tmpDir.'/playlist.m3u8');
    @unlink($tmpDir.'/segment_0000.ts');
    @rmdir($tmpDir);
});

it('returns null playback URL when no recording path', function () {
    $videoCall = VideoCall::factory()->active()->create();

    $service = new VideoRecordingService;
    expect($service->getPlaybackUrl($videoCall))->toBeNull();
});

// ── Route Authentication Tests ─────────────────────────────────────────

it('requires authentication for recording routes', function () {
    $videoCall = VideoCall::factory()->active()->create();

    $this->postJson(route('video-call.recording.start', $videoCall))
        ->assertUnauthorized();

    $this->postJson(route('video-call.recording.stop', $videoCall))
        ->assertUnauthorized();

    $this->getJson(route('video-call.recording.playback', $videoCall))
        ->assertUnauthorized();
});

it('crew user can access recording endpoints', function () {
    $crew = User::factory()->crew()->create();
    $videoCall = VideoCall::factory()->active()->create();

    $mockResponse = new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode([
        'status' => 'recording',
        'recording_id' => "vc-{$videoCall->id}",
    ])));

    $this->mock(PythonBridgeService::class, function ($mock) use ($mockResponse) {
        $mock->shouldReceive('postRaw')
            ->once()
            ->andReturn($mockResponse);
    });

    $this->actingAs($crew, 'crew')
        ->postJson(route('video-call.recording.start', $videoCall))
        ->assertSuccessful();
});
