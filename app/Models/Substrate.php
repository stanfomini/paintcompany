<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Substrate extends Model
{
    use HasFactory;

    public const SCOPE_TYPE_INTERIOR = 'interior';

    public const SCOPE_TYPE_EXTERIOR = 'exterior';

    public const SCOPE_TYPE_BOTH = 'both';

    public const CATEGORY_STANDARD = 'standard';

    public const CATEGORY_WOOD = 'wood';

    public const CATEGORY_MASONRY = 'masonry';

    public const CATEGORY_METAL = 'metal';

    protected $fillable = [
        'name',
        'code',
        'description',
        'scope_type',
        'category',
        'requires_prime',
        'requires_sand',
        'requires_scrape',
        'requires_wash',
        'prep_time_multiplier',
        'paint_time_multiplier',
        'material_multiplier',
        'default_production_rate',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requires_prime' => 'boolean',
            'requires_sand' => 'boolean',
            'requires_scrape' => 'boolean',
            'requires_wash' => 'boolean',
            'prep_time_multiplier' => 'decimal:2',
            'paint_time_multiplier' => 'decimal:2',
            'material_multiplier' => 'decimal:2',
            'default_production_rate' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get areas using this substrate.
     */
    public function estimateAreas(): HasMany
    {
        return $this->hasMany(EstimateArea::class);
    }

    /**
     * Scope to only active substrates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by scope type (interior, exterior, or both).
     */
    public function scopeForScope(Builder $query, string $scopeType): Builder
    {
        return $query->whereIn('scope_type', [$scopeType, self::SCOPE_TYPE_BOTH]);
    }

    /**
     * Scope to interior substrates.
     */
    public function scopeInterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_TYPE_INTERIOR);
    }

    /**
     * Scope to exterior substrates.
     */
    public function scopeExterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_TYPE_EXTERIOR);
    }

    /**
     * Scope by category.
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Get the combined time multiplier (prep + paint).
     */
    public function getTotalTimeMultiplierAttribute(): float
    {
        return (float) $this->prep_time_multiplier * (float) $this->paint_time_multiplier;
    }

    /**
     * Get the prep requirements as a formatted string.
     */
    public function getPrepRequirementsAttribute(): string
    {
        $requirements = [];

        if ($this->requires_wash) {
            $requirements[] = 'Pressure Wash';
        }
        if ($this->requires_scrape) {
            $requirements[] = 'Scrape';
        }
        if ($this->requires_sand) {
            $requirements[] = 'Sand';
        }
        if ($this->requires_prime) {
            $requirements[] = 'Prime';
        }

        return empty($requirements) ? 'None' : implode(', ', $requirements);
    }

    /**
     * Get available scope types.
     *
     * @return array<string, string>
     */
    public static function getScopeTypes(): array
    {
        return [
            self::SCOPE_TYPE_INTERIOR => 'Interior',
            self::SCOPE_TYPE_EXTERIOR => 'Exterior',
            self::SCOPE_TYPE_BOTH => 'Both',
        ];
    }

    /**
     * Get available categories.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_STANDARD => 'Standard (Drywall, Plaster)',
            self::CATEGORY_WOOD => 'Wood',
            self::CATEGORY_MASONRY => 'Masonry (Brick, Stucco)',
            self::CATEGORY_METAL => 'Metal',
        ];
    }
}
