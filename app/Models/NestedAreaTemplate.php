<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NestedAreaTemplate extends Model
{
    use HasFactory;

    public const CATEGORY_CLOSET = 'closet';

    public const CATEGORY_BATHROOM = 'bathroom';

    public const CATEGORY_UTILITY = 'utility';

    public const CATEGORY_OTHER = 'other';

    public const SCOPE_INTERIOR = 'interior';

    public const SCOPE_EXTERIOR = 'exterior';

    protected $fillable = [
        'name',
        'code',
        'description',
        'category',
        'scope_type',
        'fixed_price',
        'estimated_hours',  // For backend reporting (painter pay breakdown)
        'material_cost',    // For backend reporting (company analytics)
        'include_in_package_pricing',
        'default_length',
        'default_width',
        'default_height',
        'includes_walls',
        'includes_ceiling',
        'includes_trim',
        'includes_door',
        'production_rate_id',
        'substrate_id',
        'default_paint_product_id',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fixed_price' => 'decimal:2',
            'estimated_hours' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'include_in_package_pricing' => 'boolean',
            'default_length' => 'decimal:2',
            'default_width' => 'decimal:2',
            'default_height' => 'decimal:2',
            'includes_walls' => 'boolean',
            'includes_ceiling' => 'boolean',
            'includes_trim' => 'boolean',
            'includes_door' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the production rate for this template.
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * Get the substrate for this template.
     */
    public function substrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class);
    }

    /**
     * Get the default paint product for this template.
     */
    public function defaultPaintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class, 'default_paint_product_id');
    }

    /**
     * Get areas created from this template.
     */
    public function estimateAreas(): HasMany
    {
        return $this->hasMany(EstimateArea::class);
    }

    /**
     * Scope to only active templates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by category.
     */
    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to closets.
     */
    public function scopeClosets(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_CLOSET);
    }

    /**
     * Scope to bathrooms.
     */
    public function scopeBathrooms(Builder $query): Builder
    {
        return $query->where('category', self::CATEGORY_BATHROOM);
    }

    /**
     * Scope by scope type.
     */
    public function scopeForScope(Builder $query, string $scopeType): Builder
    {
        return $query->where('scope_type', $scopeType);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get the formatted price.
     */
    public function getFormattedPriceAttribute(): string
    {
        return '$'.number_format((float) $this->fixed_price, 2);
    }

    /**
     * Get what's included as a string.
     */
    public function getIncludedItemsAttribute(): string
    {
        $items = [];

        if ($this->includes_walls) {
            $items[] = 'Walls';
        }
        if ($this->includes_ceiling) {
            $items[] = 'Ceiling';
        }
        if ($this->includes_trim) {
            $items[] = 'Trim';
        }
        if ($this->includes_door) {
            $items[] = 'Door';
        }

        return empty($items) ? 'None' : implode(', ', $items);
    }

    /**
     * Get available categories.
     *
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            self::CATEGORY_CLOSET => 'Closet',
            self::CATEGORY_BATHROOM => 'Bathroom',
            self::CATEGORY_UTILITY => 'Utility Room',
            self::CATEGORY_OTHER => 'Other',
        ];
    }

    /**
     * Get available scope types.
     *
     * @return array<string, string>
     */
    public static function getScopeTypes(): array
    {
        return [
            self::SCOPE_INTERIOR => 'Interior',
            self::SCOPE_EXTERIOR => 'Exterior',
        ];
    }
}
