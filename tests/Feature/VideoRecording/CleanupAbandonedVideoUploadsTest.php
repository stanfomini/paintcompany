<?php

use App\Jobs\Python\ProcessVideoRecordingJob;
use App\Models\VideoRecording;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    Queue::fake();
});

it('recovers abandoned uploads with valid temp files', function () {
    $tempDir = storage_path('app/tmp/video-chunks');
    if (! is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $tempPath = $tempDir.'/test-chunk.mp4';
    file_put_contents($tempPath, str_repeat('x', 1024));

    $recording = VideoRecording::factory()->create([
        'status' => 'uploading',
        'original_path' => 'video-recordings/originals/test.mp4',
        'metadata' => ['temp_chunk_path' => $tempPath],
        'created_at' => now()->subMinutes(45),
    ]);

    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    $recording->refresh();

    expect($recording->status)->toBe('processing')
        ->and($recording->file_size_bytes)->toBe(1024)
        ->and($recording->metadata)->toBeNull();

    Storage::assertExists('video-recordings/originals/test.mp4');
    Queue::assertPushed(ProcessVideoRecordingJob::class, fn ($job) => $job->recording->id === $recording->id);
    expect(file_exists($tempPath))->toBeFalse();
});

it('marks abandoned uploads as failed when temp file is missing', function () {
    $recording = VideoRecording::factory()->create([
        'status' => 'uploading',
        'original_path' => 'video-recordings/originals/test.mp4',
        'metadata' => ['temp_chunk_path' => '/nonexistent/path.mp4'],
        'created_at' => now()->subMinutes(45),
    ]);

    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    $recording->refresh();

    expect($recording->status)->toBe('failed')
        ->and($recording->error_message)->toContain('abandoned');

    Queue::assertNotPushed(ProcessVideoRecordingJob::class);
});

it('marks abandoned uploads as failed when temp file is empty', function () {
    $tempDir = storage_path('app/tmp/video-chunks');
    if (! is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    $tempPath = $tempDir.'/empty-chunk.mp4';
    file_put_contents($tempPath, '');

    $recording = VideoRecording::factory()->create([
        'status' => 'uploading',
        'original_path' => 'video-recordings/originals/test.mp4',
        'metadata' => ['temp_chunk_path' => $tempPath],
        'created_at' => now()->subMinutes(45),
    ]);

    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    $recording->refresh();

    expect($recording->status)->toBe('failed')
        ->and($recording->error_message)->toContain('abandoned');

    Queue::assertNotPushed(ProcessVideoRecordingJob::class);
});

it('ignores recent uploads that are not yet abandoned', function () {
    $recording = VideoRecording::factory()->create([
        'status' => 'uploading',
        'original_path' => 'video-recordings/originals/test.mp4',
        'created_at' => now()->subMinutes(10),
    ]);

    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    $recording->refresh();

    expect($recording->status)->toBe('uploading');

    Queue::assertNotPushed(ProcessVideoRecordingJob::class);
});

it('ignores recordings in other statuses', function () {
    $processing = VideoRecording::factory()->processing()->create([
        'created_at' => now()->subHours(2),
    ]);
    $ready = VideoRecording::factory()->ready()->create([
        'created_at' => now()->subHours(2),
    ]);

    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    expect($processing->refresh()->status)->toBe('processing')
        ->and($ready->refresh()->status)->toBe('ready');

    Queue::assertNotPushed(ProcessVideoRecordingJob::class);
});

it('accepts custom minutes option', function () {
    $recording = VideoRecording::factory()->create([
        'status' => 'uploading',
        'original_path' => 'video-recordings/originals/test.mp4',
        'metadata' => ['temp_chunk_path' => '/nonexistent/path.mp4'],
        'created_at' => now()->subMinutes(15),
    ]);

    // Default 30 minutes - should not touch it
    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    expect($recording->refresh()->status)->toBe('uploading');

    // With 10 minutes - should clean it up
    $this->artisan('video:cleanup-abandoned --minutes=10')
        ->assertSuccessful();

    expect($recording->refresh()->status)->toBe('failed');
});

it('cleans up orphaned temp files older than 2 hours', function () {
    $tempDir = storage_path('app/tmp/video-chunks');
    if (! is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $oldFile = $tempDir.'/old-orphan.mp4';
    file_put_contents($oldFile, 'old data');
    touch($oldFile, now()->subHours(3)->getTimestamp());

    $recentFile = $tempDir.'/recent-file.mp4';
    file_put_contents($recentFile, 'recent data');

    $this->artisan('video:cleanup-abandoned')
        ->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse()
        ->and(file_exists($recentFile))->toBeTrue();

    // Cleanup
    @unlink($recentFile);
});

it('reports no abandoned uploads when none exist', function () {
    $this->artisan('video:cleanup-abandoned')
        ->expectsOutput('No abandoned uploads found.')
        ->assertSuccessful();
});
