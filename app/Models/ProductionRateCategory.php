<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionRateCategory extends Model
{
    /** @use HasFactory<\Database\Factories\ProductionRateCategoryFactory> */
    use HasFactory;

    public const TYPE_INTERIOR = 'interior';

    public const TYPE_EXTERIOR = 'exterior';

    public const TYPE_SPECIALTY = 'specialty';

    public const TYPES = [
        self::TYPE_INTERIOR => 'Interior',
        self::TYPE_EXTERIOR => 'Exterior',
        self::TYPE_SPECIALTY => 'Specialty',
    ];

    protected $fillable = [
        'name',
        'type',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Get the production rates for this category.
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ProductionRate::class, 'category_id');
    }

    /**
     * Scope to filter active categories.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
