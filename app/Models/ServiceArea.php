<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'city',
        'state',
        'slug',
        'zip_codes',
        'latitude',
        'longitude',
        'territory_id',
        'meta_title',
        'meta_description',
        'hero_text',
        'specialties_text',
        'local_content',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'zip_codes' => 'array',
            'local_content' => 'array',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
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
        return $value ?? "House Painting {$this->city} CO | Interior & Exterior | Painting Made Easy Denver";
    }

    public function getMetaDescriptionAttribute(?string $value): string
    {
        if ($value) {
            return $value;
        }

        $neighborhoods = $this->local_content['neighborhoods'] ?? [];
        $neighborhoodText = ! empty($neighborhoods)
            ? ' Serving '.implode(', ', array_slice($neighborhoods, 0, 3)).' and more.'
            : '';

        return "Professional house painting in {$this->city}, Colorado. Interior & exterior with real-time tracking. Free estimates.{$neighborhoodText}";
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_area_service')
            ->withPivot('custom_h1', 'custom_intro', 'is_featured', 'sort_order')
            ->withTimestamps();
    }

    public function getUrlAttribute(): string
    {
        return route('areas.show', $this->slug);
    }

    public function keywordAssignments(): HasMany
    {
        return $this->hasMany(KeywordPageAssignment::class);
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'keyword_page_assignments')
            ->withPivot(['role', 'custom_sentence', 'service_id'])
            ->withTimestamps();
    }
}
