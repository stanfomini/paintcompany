<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'category',
        'tagline',
        'meta_description',
        'overview',
        'materials',
        'steps',
        'tips',
        'cost_factors',
        'faqs',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'materials' => 'array',
            'steps' => 'array',
            'tips' => 'array',
            'cost_factors' => 'array',
            'faqs' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function serviceAreas(): BelongsToMany
    {
        return $this->belongsToMany(ServiceArea::class, 'service_area_service')
            ->withPivot('custom_h1', 'custom_intro', 'is_featured', 'sort_order')
            ->withTimestamps();
    }

    public function getUrlAttribute(): string
    {
        return route('services.show', $this->slug);
    }

    public function keywordAssignments(): HasMany
    {
        return $this->hasMany(KeywordPageAssignment::class);
    }

    public function keywords(): BelongsToMany
    {
        return $this->belongsToMany(Keyword::class, 'keyword_page_assignments')
            ->withPivot(['role', 'custom_sentence', 'service_area_id'])
            ->withTimestamps();
    }
}
