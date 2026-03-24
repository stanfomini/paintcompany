<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class LandingPageSectionPhoto extends Model
{
    protected $fillable = [
        'landing_page_video_section_id',
        'path',
        'thumbnail_path',
        'disk',
        'caption',
        'original_filename',
        'deal_id',
        'property_photo_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(LandingPageVideoSection::class, 'landing_page_video_section_id');
    }

    public function sourcePhoto(): BelongsTo
    {
        return $this->belongsTo(PropertyPhoto::class, 'property_photo_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function getPhotoUrl(string $type = 'original'): ?string
    {
        $path = match ($type) {
            'thumbnail' => $this->thumbnail_path ?? $this->path,
            default => $this->path,
        };

        if (! $path) {
            return null;
        }

        // Use Bunny CDN for optimized delivery when enabled
        if (config('services.bunny.enabled')) {
            return cdn_image($path, updatedAt: $this->updated_at);
        }

        $diskName = $this->disk ?? config('filesystems.default');
        $disk = Storage::disk($diskName);

        try {
            if (method_exists($disk, 'temporaryUrl')) {
                $cacheKey = "section_photo_url:{$this->id}:{$type}";

                return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($disk, $path) {
                    return $disk->temporaryUrl($path, now()->addMinutes(60));
                });
            }
        } catch (\Throwable $e) {
            // Disk doesn't support temporaryUrl or missing config
        }

        return $disk->url($path);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->getPhotoUrl('original');
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        return $this->getPhotoUrl('thumbnail');
    }

    protected static function booted(): void
    {
        static::deleting(function (self $photo) {
            $diskName = $photo->disk ?? config('filesystems.default');
            $disk = Storage::disk($diskName);

            if ($photo->path && $disk->exists($photo->path)) {
                $disk->delete($photo->path);
            }

            if ($photo->thumbnail_path && $photo->thumbnail_path !== $photo->path && $disk->exists($photo->thumbnail_path)) {
                $disk->delete($photo->thumbnail_path);
            }

            Cache::forget("section_photo_url:{$photo->id}:original");
            Cache::forget("section_photo_url:{$photo->id}:thumbnail");
        });
    }
}
