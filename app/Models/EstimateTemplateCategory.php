<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EstimateTemplateCategory extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateTemplateCategoryFactory> */
    use HasFactory;

    public const TYPE_ROOM = 'room';

    public const TYPE_SURFACE = 'surface';

    public const TYPE_ITEM = 'item';

    public const TYPE_ADDON = 'addon';

    public const TYPES = [
        self::TYPE_ROOM => 'Room',
        self::TYPE_SURFACE => 'Surface',
        self::TYPE_ITEM => 'Item',
        self::TYPE_ADDON => 'Add-on',
    ];

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'type',
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

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (EstimateTemplateCategory $category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });

        static::updating(function (EstimateTemplateCategory $category) {
            if ($category->isDirty('name') && ! $category->isDirty('slug')) {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * Get the type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Get the templates for this category.
     */
    public function templates(): HasMany
    {
        return $this->hasMany(EstimateTemplate::class, 'template_category_id')->orderBy('sort_order');
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
