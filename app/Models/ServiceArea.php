<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'city',
        'state',
        'slug',
        'zip_codes',
        'territory_id',
        'meta_title',
        'meta_description',
        'hero_text',
        'specialties_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'zip_codes' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'territory_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFindByZip(Builder $query, string $zip): Builder
    {
        return $query->whereJsonContains('zip_codes', $zip);
    }

    public function getMetaTitleAttribute(?string $value): string
    {
        return $value ?? "Professional Painting Services in {$this->city}, {$this->state} | Painting Made Easy Denver";
    }

    public function getMetaDescriptionAttribute(?string $value): string
    {
        return $value ?? "Looking for a house painter in {$this->city}, {$this->state}? Painting Made Easy Denver offers interior & exterior painting with real-time tracking, daily photo updates, and a satisfaction guarantee. Free estimates.";
    }

    public function getUrlAttribute(): string
    {
        return route('areas.show', $this->slug);
    }
}
