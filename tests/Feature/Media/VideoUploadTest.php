<?php

use App\Jobs\Python\ProcessVideoRecordingJob;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    Queue::fake();
});

describe('Video Upload API', function () {
    it('uploads a video file successfully', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', [
                'file' => UploadedFile::fake()->create('training.mp4', 5000, 'video/mp4'),
                'title' => 'Interior Painting Training',
            ]);

        $response->assertCreated()
            ->assertJsonStructure(['id', 'status', 'share_token']);

        $this->assertDatabaseHas('video_recordings', [
            'user_id' => $user->id,
            'title' => 'Interior Painting Training',
            'status' => 'uploading',
        ]);
    });

    it('uploads without a title', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', [
                'file' => UploadedFile::fake()->create('video.mp4', 5000, 'video/mp4'),
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('video_recordings', [
            'user_id' => $user->id,
            'title' => null,
        ]);
    });

    it('requires authentication', function () {
        $response = $this->postJson('/api/video-recordings', [
            'file' => UploadedFile::fake()->create('video.mp4', 5000, 'video/mp4'),
        ]);

        $response->assertUnauthorized();
    });

    it('rejects invalid file types', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', [
                'file' => UploadedFile::fake()->create('document.pdf', 5000, 'application/pdf'),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('rejects files exceeding 200MB', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', [
                'file' => UploadedFile::fake()->create('huge.mp4', 210000, 'video/mp4'),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('rejects request without file', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    });

    it('accepts webm files', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', [
                'file' => UploadedFile::fake()->create('screen-recording.webm', 5000, 'video/webm'),
            ]);

        $response->assertCreated();
    });

    it('accepts quicktime files', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/video-recordings', [
                'file' => UploadedFile::fake()->create('ipad-recording.mov', 5000, 'video/quicktime'),
            ]);

        $response->assertCreated();
    });
});

describe('Video Shareable URL', function () {
    it('generates a shareable URL with share token', function () {
        $video = VideoRecording::factory()->ready()->create();

        $url = $video->getShareableUrl();

        expect($url)->toContain('/v/'.$video->share_token);
    });

    it('auto-generates share token on creation', function () {
        $video = VideoRecording::factory()->create();

        expect($video->share_token)->not->toBeNull()
            ->and(strlen($video->share_token))->toBe(32);
    });
});

describe('Video Playback OG Meta Tags', function () {
    it('renders OG meta tags on public playback page', function () {
        $video = VideoRecording::factory()->ready()->create([
            'title' => 'How to Cut In Like a Pro',
            'description' => 'Learn cutting techniques',
        ]);

        $response = $this->get('/v/'.$video->share_token);

        $response->assertSuccessful()
            ->assertSee('og:title', false)
            ->assertSee('How to Cut In Like a Pro', false)
            ->assertSee('og:description', false)
            ->assertSee('og:image', false)
            ->assertSee('twitter:card', false);
    });

    it('returns 404 for invalid share token', function () {
        $this->get('/v/nonexistent-token-12345678')->assertNotFound();
    });
});

describe('Video Upload - My Videos Component', function () {
    it('renders the upload button', function () {
        $user = User::factory()->internal()->create();

        Livewire\Livewire::actingAs($user)
            ->test('media.my-videos')
            ->assertStatus(200)
            ->assertSee('Upload Video');
    });

    it('refreshes video list on video-uploaded event', function () {
        $user = User::factory()->internal()->create();

        $video = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

        Livewire\Livewire::actingAs($user)
            ->test('media.my-videos')
            ->assertSee($video->title)
            ->dispatch('video-uploaded')
            ->assertStatus(200);
    });
});

describe('Chunked Video Upload (File Upload)', function () {
    it('uploads a large file via chunked upload flow', function () {
        Storage::fake();
        Queue::fake();

        $user = User::factory()->internal()->create();

        // Step 1: Start session
        $startResponse = $this->actingAs($user)
            ->postJson('/api/video-recordings/start', [
                'mime_type' => 'video/mp4',
                'title' => 'Large Training Video',
            ]);

        $startResponse->assertCreated()
            ->assertJsonStructure(['id', 'status', 'share_token']);

        $recordingId = $startResponse->json('id');
        $recording = VideoRecording::find($recordingId);

        // Step 2: Upload multiple chunks (simulating a larger file)
        $chunkData = str_repeat('x', 1024); // 1KB chunks for test

        for ($i = 0; $i < 3; $i++) {
            $chunk = UploadedFile::fake()->createWithContent("chunk_{$i}.mp4", $chunkData);

            $this->actingAs($user)
                ->postJson("/api/video-recordings/{$recordingId}/chunk", [
                    'chunk' => $chunk,
                    'chunk_index' => $i,
                ])
                ->assertSuccessful()
                ->assertJsonPath('received', true)
                ->assertJsonPath('chunk_index', $i);
        }

        // Step 3: Finalize
        $this->actingAs($user)
            ->postJson("/api/video-recordings/{$recordingId}/finalize")
            ->assertSuccessful()
            ->assertJsonPath('status', 'processing');

        // Verify recording was created and is processing
        $recording->refresh();
        expect($recording->status)->toBe('processing')
            ->and($recording->title)->toBe('Large Training Video')
            ->and($recording->file_size_bytes)->toBe(3072); // 3 x 1024

        Queue::assertPushed(ProcessVideoRecordingJob::class);
    });

    it('handles upload cancellation gracefully', function () {
        Storage::fake();

        $user = User::factory()->internal()->create();

        // Start session
        $startResponse = $this->actingAs($user)
            ->postJson('/api/video-recordings/start', [
                'mime_type' => 'video/mp4',
            ]);

        $recordingId = $startResponse->json('id');

        // Upload one chunk
        $chunk = UploadedFile::fake()->createWithContent('chunk_0.mp4', str_repeat('x', 512));
        $this->actingAs($user)
            ->postJson("/api/video-recordings/{$recordingId}/chunk", [
                'chunk' => $chunk,
                'chunk_index' => 0,
            ])
            ->assertSuccessful();

        // Don't finalize - simulating cancellation
        // Recording should stay in uploading state
        $recording = VideoRecording::find($recordingId);
        expect($recording->status)->toBe('uploading');
    });
});
