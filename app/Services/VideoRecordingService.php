<?php

namespace App\Services;

use App\Models\VideoCall;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class VideoRecordingService
{
    /**
     * Store HLS files from the Python service's temp output directory.
     */
    public function storeFromPythonOutput(VideoCall $videoCall, string $outputDir): void
    {
        $basePath = "recordings/{$videoCall->job_id}/{$videoCall->id}";

        if (! is_dir($outputDir)) {
            return;
        }

        // Upload all HLS files (manifest + segments)
        foreach (File::files($outputDir) as $file) {
            Storage::disk('video-recordings')->putFileAs(
                $basePath,
                $file,
                $file->getFilename()
            );
        }

        $videoCall->update([
            'recording_path' => "{$basePath}/playlist.m3u8",
            'recording_disk' => 'video-recordings',
        ]);
    }

    /**
     * Get a playback URL for a video call recording.
     *
     * For local disk, returns a direct URL.
     * For S3/Wasabi, returns a temporary signed URL.
     */
    public function getPlaybackUrl(VideoCall $videoCall): ?string
    {
        if (! $videoCall->recording_path) {
            return null;
        }

        $disk = Storage::disk('video-recordings');
        $driver = config('filesystems.disks.video-recordings.driver', 'local');

        if ($driver === 'local') {
            return $disk->url($videoCall->recording_path);
        }

        // S3/Wasabi: generate a temporary signed URL (4 hours)
        return $disk->temporaryUrl(
            $videoCall->recording_path,
            now()->addHours(4)
        );
    }
}
