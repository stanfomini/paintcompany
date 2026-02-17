<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('storage_url')) {
    /**
     * Get a URL for a file on the default storage disk.
     *
     * Returns a temporary signed URL for cloud disks (S3, etc.)
     * and a regular public URL for local disks.
     */
    function storage_url(?string $path, int $minutes = 60): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk();

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($path, now()->addMinutes($minutes));
            } catch (RuntimeException $e) {
                // Local disk doesn't support temporaryUrl
            }
        }

        return $disk->url($path);
    }
}
