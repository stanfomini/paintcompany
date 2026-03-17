<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowcaseMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'label',
        'video_recording_id',
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

    public function video(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class, 'video_recording_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Get the publicly accessible URL for this showcase media's video.
     */
    public function getPublicUrl(): ?string
    {
        return $this->video?->getShareableUrl() ?? $this->video?->getVideoUrl();
    }

    /**
     * Find an active showcase media record by slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::active()->where('slug', $slug)->first();
    }
}
