<?php

namespace App\Jobs\Python;

use App\Models\VideoRecording;
use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessVideoRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(
        public VideoRecording $recording
    ) {}

    public function handle(PythonBridgeService $python): void
    {
        Log::info('Processing video recording', ['id' => $this->recording->id]);

        $this->recording->update(['status' => 'processing']);

        try {
            $localDisk = Storage::disk('video-recordings');
            $originalPath = $localDisk->path($this->recording->original_path);

            if (! file_exists($originalPath)) {
                throw new \RuntimeException('Original video file not found: '.$this->recording->original_path);
            }

            // Send to Python for processing
            $response = $python->processVideo($originalPath, (string) $this->recording->id);

            if (! $response->successful()) {
                throw new \RuntimeException('Python processing failed: '.$response->body());
            }

            $result = $response->json();

            if (! ($result['success'] ?? false)) {
                throw new \RuntimeException('Processing returned failure: '.($result['detail'] ?? 'Unknown error'));
            }

            // Determine the final storage disk (cloud if configured, else local)
            $finalDisk = Storage::disk('video-recordings-cloud');
            $finalDiskName = 'video-recordings-cloud';

            // Copy processed MP4 to final storage
            $videoStoragePath = 'videos/'.$this->recording->id.'.mp4';
            if (isset($result['video_path']) && file_exists($result['video_path'])) {
                $finalDisk->put($videoStoragePath, file_get_contents($result['video_path']));
            }

            // Copy thumbnail to final storage
            $thumbnailStoragePath = null;
            if (isset($result['thumbnail_path']) && file_exists($result['thumbnail_path'])) {
                $thumbnailStoragePath = 'thumbnails/'.$this->recording->id.'.jpg';
                $finalDisk->put($thumbnailStoragePath, file_get_contents($result['thumbnail_path']));
            }

            // Update the recording record to point at the final disk
            $this->recording->update([
                'status' => 'ready',
                'disk' => $finalDiskName,
                'video_path' => $videoStoragePath,
                'thumbnail_path' => $thumbnailStoragePath,
                'duration_seconds' => $result['duration'] ?? null,
                'file_size_bytes' => $result['file_size'] ?? null,
                'width' => $result['width'] ?? null,
                'height' => $result['height'] ?? null,
                'mime_type' => 'video/mp4',
                'error_message' => null,
            ]);

            // Clean up local originals now that files are on cloud
            if ($localDisk->exists($this->recording->original_path)) {
                $localDisk->delete($this->recording->original_path);
            }

            // Clean up temp files on Python side
            $python->cleanupVideoProcessing((string) $this->recording->id);

            Log::info('Video recording processed successfully', [
                'id' => $this->recording->id,
                'disk' => $finalDiskName,
                'duration' => $result['duration'] ?? 0,
            ]);
        } catch (\Exception $e) {
            Log::warning('Video recording processing failed, falling back to original', [
                'id' => $this->recording->id,
                'error' => $e->getMessage(),
            ]);

            // Fall back to serving the original file directly from local disk
            $this->recording->update([
                'status' => 'ready',
                'video_path' => null, // getVideoUrl() will fall back to original_path
                'error_message' => 'Processing failed, using original recording',
            ]);

            // Don't re-throw - the video is still usable without transcoding
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::warning('Video recording job failed permanently, falling back to original', [
            'id' => $this->recording->id,
            'error' => $exception?->getMessage(),
        ]);

        // Fall back to serving the original file directly
        $this->recording->update([
            'status' => 'ready',
            'video_path' => null, // getVideoUrl() will fall back to original_path
            'error_message' => $exception?->getMessage() ?? 'Processing failed, using original recording',
        ]);
    }
}
