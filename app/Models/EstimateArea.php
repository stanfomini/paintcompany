<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateArea extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateAreaFactory> */
    use HasFactory;

    public const SCOPE_INTERIOR = 'interior';

    public const SCOPE_EXTERIOR = 'exterior';

    public const APPLICATION_BRUSH_ROLL = 'brush_roll';

    public const APPLICATION_SPRAY = 'spray';

    protected $fillable = [
        'estimate_id',
        'parent_area_id',
        'nested_area_template_id',
        'substrate_id',
        'name',
        'scope_type',
        'length',
        'width',
        'height',
        'wall_sqft',
        'ceiling_sqft',
        'floor_sqft',
        'perimeter_lnft',
        'baseboard_lnft',
        'application_method',
        'is_fixed_price',
        'fixed_price_amount',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'length' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
            'wall_sqft' => 'decimal:2',
            'ceiling_sqft' => 'decimal:2',
            'floor_sqft' => 'decimal:2',
            'perimeter_lnft' => 'decimal:2',
            'baseboard_lnft' => 'decimal:2',
            'is_fixed_price' => 'boolean',
            'fixed_price_amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateArea $area) {
            // Skip auto-calculation for fixed price areas (closets, etc.)
            if ($area->is_fixed_price) {
                return;
            }

            // Auto-calculate square footage from dimensions
            if ($area->length && $area->width && $area->height) {
                // Perimeter in linear feet = 2 * (length + width)
                $area->perimeter_lnft = 2 * ($area->length + $area->width);

                // Wall sqft = perimeter * height
                $area->wall_sqft = $area->perimeter_lnft * $area->height;

                // Ceiling sqft = length * width
                $area->ceiling_sqft = $area->length * $area->width;

                // Floor sqft = length * width
                $area->floor_sqft = $area->length * $area->width;

                // Baseboard = perimeter (default, can be overridden)
                if (! $area->baseboard_lnft) {
                    $area->baseboard_lnft = $area->perimeter_lnft;
                }
            }
        });
    }

    /**
     * Get the estimate for this area.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the parent area (for nested areas like closets).
     */
    public function parentArea(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class, 'parent_area_id');
    }

    /**
     * Get child/nested areas (e.g., closets attached to a bedroom).
     */
    public function childAreas(): HasMany
    {
        return $this->hasMany(EstimateArea::class, 'parent_area_id')->orderBy('sort_order');
    }

    /**
     * Get the nested area template (if this is a templated nested area).
     */
    public function nestedAreaTemplate(): BelongsTo
    {
        return $this->belongsTo(NestedAreaTemplate::class);
    }

    /**
     * Get the substrate for this area.
     */
    public function substrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class);
    }

    /**
     * Get the surfaces for this area.
     */
    public function surfaces(): HasMany
    {
        return $this->hasMany(EstimateAreaSurface::class, 'area_id')->orderBy('sort_order');
    }

    /**
     * Get paint selections for this area.
     */
    public function paintSelections(): HasMany
    {
        return $this->hasMany(EstimateAreaPaintSelection::class);
    }

    /**
     * Get the total of all included surfaces.
     */
    public function getSurfacesTotalAttribute(): float
    {
        return (float) $this->surfaces()
            ->where('is_included', true)
            ->sum('total');
    }

    /**
     * Get the total fixed price including nested areas.
     */
    public function getTotalFixedPriceAttribute(): float
    {
        $total = $this->is_fixed_price ? (float) $this->fixed_price_amount : 0;

        // Add fixed prices from nested areas
        foreach ($this->childAreas as $child) {
            if ($child->is_fixed_price) {
                $total += (float) $child->fixed_price_amount;
            }
        }

        return $total;
    }

    /**
     * Get total paint material cost for this area.
     */
    public function getTotalPaintCostAttribute(): float
    {
        return (float) $this->paintSelections()->sum('total_material_cost');
    }

    /**
     * Check if this is a nested/child area.
     */
    public function getIsNestedAttribute(): bool
    {
        return $this->parent_area_id !== null;
    }

    /**
     * Scope to get only top-level areas (not nested).
     */
    public function scopeTopLevel(Builder $query): Builder
    {
        return $query->whereNull('parent_area_id');
    }

    /**
     * Scope to get only nested areas.
     */
    public function scopeNested(Builder $query): Builder
    {
        return $query->whereNotNull('parent_area_id');
    }

    /**
     * Scope by scope type (interior/exterior).
     */
    public function scopeForScope(Builder $query, string $scopeType): Builder
    {
        return $query->where('scope_type', $scopeType);
    }

    /**
     * Scope to interior areas.
     */
    public function scopeInterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_INTERIOR);
    }

    /**
     * Scope to exterior areas.
     */
    public function scopeExterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_EXTERIOR);
    }

    /**
     * Scope to get areas with only included surfaces.
     */
    public function scopeWithIncludedSurfaces(Builder $query): Builder
    {
        return $query->with(['surfaces' => function ($query) {
            $query->where('is_included', true);
        }]);
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

    /**
     * Get available application methods.
     *
     * @return array<string, string>
     */
    public static function getApplicationMethods(): array
    {
        return [
            self::APPLICATION_BRUSH_ROLL => 'Brush/Roll',
            self::APPLICATION_SPRAY => 'Spray',
        ];
    }
}
