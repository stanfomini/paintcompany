<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudioTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\StudioTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category',
        'thumbnail_path',
        'structure_json',
        'system_prompt',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'structure_json' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class, 'template_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
