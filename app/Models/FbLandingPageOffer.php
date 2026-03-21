<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class FbLandingPageOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'title',
        'headline',
        'subheadline',
        'offer_bullets',
        'cta_text',
        'video_recording_id',
        'hero_photo_path',
        'hero_photo_disk',
        'is_active',
        'fb_pixel_id',
        'lead_source_id',
    ];

    protected function casts(): array
    {
        return [
            'offer_bullets' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function videoRecording(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class);
    }

    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(FbLandingPageView::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function findBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Get the hero photo URL.
     */
    public function getHeroPhotoUrlAttribute(): ?string
    {
        if (! $this->hero_photo_path) {
            return null;
        }

        return Storage::disk($this->hero_photo_disk ?? 'public')->url($this->hero_photo_path);
    }
}
