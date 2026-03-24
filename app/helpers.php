<?php

use Illuminate\Support\Facades\Storage;

if (! function_exists('storage_url')) {
    /**
     * Get a URL for a file on the default storage disk.
     *
     * Returns a temporary signed URL for cloud disks (S3, etc.)
     * and a regular public URL for local disks.
     */
    function storage_url(?string $path, int $minutes = 60, ?string $diskName = null): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = Storage::disk($diskName ?? config('filesystems.default'));

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

if (! function_exists('cdn_image')) {
    /**
     * Generate a Bunny CDN optimized image URL.
     * Falls back to direct Storage URL when CDN is disabled.
     */
    function cdn_image(string $path, ?int $width = null, int $quality = 80, ?\DateTimeInterface $updatedAt = null): string
    {
        if (! config('services.bunny.enabled') || ! config('services.bunny.cdn_url')) {
            return storage_url($path) ?? '';
        }

        $url = config('services.bunny.cdn_url').'/'.ltrim($path, '/');
        $params = ['quality' => $quality];
        if ($width) {
            $params['width'] = $width;
        }
        if ($updatedAt) {
            $params['v'] = $updatedAt->timestamp;
        }

        return $url.'?'.http_build_query($params);
    }
}

if (! function_exists('cdn_srcset')) {
    /**
     * Generate a responsive srcset string for Bunny CDN images.
     */
    function cdn_srcset(string $path, array $widths = [400, 800, 1200], int $quality = 80): string
    {
        return collect($widths)
            ->map(fn ($w) => cdn_image($path, $w, $quality)." {$w}w")
            ->implode(', ');
    }
}

if (! function_exists('cdn_private')) {
    /**
     * Generate a Bunny CDN token-authenticated URL for private images.
     */
    function cdn_private(string $path, int $expiresInMinutes = 60): string
    {
        if (! config('services.bunny.enabled') || ! config('services.bunny.cdn_url')) {
            return storage_url($path) ?? '';
        }

        $path = '/'.ltrim($path, '/');
        $expires = now()->addMinutes($expiresInMinutes)->timestamp;
        $securityKey = config('services.bunny.token_key');
        $hashableBase = $securityKey.$path.$expires;
        $token = rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode(hash('sha256', $hashableBase, true))), '=');

        return config('services.bunny.cdn_url').$path.'?token='.$token.'&expires='.$expires;
    }
}
