<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandingPageVideoSection extends Model
{
    protected $fillable = ['slug', 'title', 'subtitle', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LandingPageVideoSectionItem::class)->orderBy('sort_order');
    }

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(VideoRecording::class, 'landing_page_video_section_items')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
