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

        $diskName = config('filesystems.default');

        // Upload all HLS files (manifest + segments)
        foreach (File::files($outputDir) as $file) {
            Storage::putFileAs(
                $basePath,
                $file,
                $file->getFilename()
            );
        }

        $videoCall->update([
            'recording_path' => "{$basePath}/playlist.m3u8",
            'recording_disk' => $diskName,
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

        return storage_url($videoCall->recording_path, 240);
    }
}
