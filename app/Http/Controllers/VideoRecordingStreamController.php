<?php

namespace App\Http\Controllers;

use App\Models\VideoRecording;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VideoRecordingStreamController extends Controller
{
    /**
     * Stream a video recording with proper range request support.
     *
     * For local disks, serves via BinaryFileResponse (supports Range/206).
     * For cloud disks (S3), redirects to a presigned temporary URL.
     */
    public function stream(VideoRecording $recording): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        $path = $recording->getPlayablePath();

        abort_unless($path, 404);

        $diskName = $recording->disk ?? config('filesystems.default');
        $disk = Storage::disk($diskName);

        abort_unless($disk->exists($path), 404);

        $mimeType = $recording->mime_type ?? 'video/mp4';

        // For cloud disks (S3, etc.), redirect to a presigned URL
        // so the browser downloads directly from the CDN/S3
        if (method_exists($disk, 'temporaryUrl')) {
            try {
                $url = $disk->temporaryUrl($path, now()->addMinutes(60));

                return redirect()->away($url);
            } catch (\RuntimeException $e) {
                // Falls through to direct serving
            }
        }

        // For local disks, serve via BinaryFileResponse (supports Range/206)
        $fullPath = $disk->path($path);

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function thumbnail(VideoRecording $recording): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        abort_unless($recording->thumbnail_path, 404);

        $diskName = $recording->disk ?? config('filesystems.default');
        $disk = Storage::disk($diskName);

        abort_unless($disk->exists($recording->thumbnail_path), 404);

        // For cloud disks, redirect to presigned URL
        if (method_exists($disk, 'temporaryUrl')) {
            try {
                $url = $disk->temporaryUrl($recording->thumbnail_path, now()->addMinutes(60));

                return redirect()->away($url);
            } catch (\RuntimeException $e) {
                // Falls through to direct serving
            }
        }

        $fullPath = $disk->path($recording->thumbnail_path);

        return response()->file($fullPath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
