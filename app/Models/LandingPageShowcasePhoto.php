<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class LandingPageShowcasePhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'before_photo_path',
        'after_photo_path',
        'before_thumbnail_path',
        'after_thumbnail_path',
        'disk',
        'caption',
        'deal_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function getPhotoUrl(string $type): ?string
    {
        $path = match ($type) {
            'before' => $this->before_photo_path,
            'after' => $this->after_photo_path,
            'before_thumbnail' => $this->before_thumbnail_path ?? $this->before_photo_path,
            'after_thumbnail' => $this->after_thumbnail_path ?? $this->after_photo_path,
            default => null,
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

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                $cacheKey = "showcase_photo_url:{$this->id}:{$type}";

                return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($disk, $path) {
                    return $disk->temporaryUrl($path, now()->addMinutes(60));
                });
            } catch (\RuntimeException $e) {
                // Local disk doesn't support temporaryUrl
            }
        }

        return $disk->url($path);
    }

    public function getBeforeUrlAttribute(): ?string
    {
        return $this->getPhotoUrl('before');
    }

    public function getAfterUrlAttribute(): ?string
    {
        return $this->getPhotoUrl('after');
    }

    public function getBeforeThumbnailUrlAttribute(): ?string
    {
        return $this->getPhotoUrl('before_thumbnail');
    }

    public function getAfterThumbnailUrlAttribute(): ?string
    {
        return $this->getPhotoUrl('after_thumbnail');
    }
}
