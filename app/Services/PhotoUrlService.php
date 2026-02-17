<?php

namespace App\Services;

use App\Models\PropertyPhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PhotoUrlService
{
    /**
     * Get the URL for a photo variant.
     */
    public function getUrl(PropertyPhoto $photo, string $variant = 'original'): ?string
    {
        $path = match ($variant) {
            'thumbnail' => $photo->thumbnail_path,
            'medium' => $photo->medium_path,
            'annotated' => $photo->annotated_path,
            default => $photo->original_path ?? $photo->path,
        };

        if (! $path) {
            return null;
        }

        $diskName = $photo->disk ?? config('filesystems.default');
        $disk = Storage::disk($diskName);

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                $cacheKey = "photo_url:{$photo->id}:{$variant}";

                return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($disk, $path) {
                    return $disk->temporaryUrl($path, now()->addMinutes(60));
                });
            } catch (\RuntimeException $e) {
                // Local disk doesn't support temporaryUrl
            }
        }

        return $disk->url($path);
    }

    /**
     * Get URLs for multiple photos and variants.
     *
     * @param  array<string>  $variants
     * @return array<int, array<string, string|null>>
     */
    public function batchUrls(Collection $photos, array $variants = ['thumbnail']): array
    {
        $result = [];

        foreach ($photos as $photo) {
            $urls = [];

            foreach ($variants as $variant) {
                $urls[$variant] = $this->getUrl($photo, $variant);
            }

            $result[$photo->id] = $urls;
        }

        return $result;
    }

    /**
     * Invalidate cached URLs for a photo.
     */
    public function invalidateCache(PropertyPhoto $photo): void
    {
        foreach (['original', 'thumbnail', 'medium', 'annotated'] as $variant) {
            Cache::forget("photo_url:{$photo->id}:{$variant}");
        }
    }
}
