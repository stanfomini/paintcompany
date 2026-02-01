<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaTemplateSurface extends Model
{
    /** @use HasFactory<\Database\Factories\AreaTemplateSurfaceFactory> */
    use HasFactory;

    public const SURFACE_TYPE_WALLS = 'walls';

    public const SURFACE_TYPE_CEILING = 'ceiling';

    public const SURFACE_TYPE_TRIM = 'trim';

    public const SURFACE_TYPE_DOORS = 'doors';

    public const SURFACE_TYPE_WINDOWS = 'windows';

    public const SURFACE_TYPE_FLOOR = 'floor';

    public const SURFACE_TYPE_OTHER = 'other';

    public const SURFACE_TYPES = [
        self::SURFACE_TYPE_WALLS,
        self::SURFACE_TYPE_CEILING,
        self::SURFACE_TYPE_TRIM,
        self::SURFACE_TYPE_DOORS,
        self::SURFACE_TYPE_WINDOWS,
        self::SURFACE_TYPE_FLOOR,
        self::SURFACE_TYPE_OTHER,
    ];

    public const CALC_METHOD_RATIO = 'ratio';

    public const CALC_METHOD_PER_100SQFT = 'per_100sqft';

    public const CALC_METHOD_PER_LNFT = 'per_lnft';

    public const CALC_METHOD_FIXED_COUNT = 'fixed_count';

    public const CALC_METHOD_FIXED_PRICE = 'fixed_price';

    public const CALCULATION_METHODS = [
        self::CALC_METHOD_RATIO,
        self::CALC_METHOD_PER_100SQFT,
        self::CALC_METHOD_PER_LNFT,
        self::CALC_METHOD_FIXED_COUNT,
        self::CALC_METHOD_FIXED_PRICE,
    ];

    public const UNIT_TYPE_SQFT = 'sqft';

    public const UNIT_TYPE_LNFT = 'lnft';

    public const UNIT_TYPE_UNITS = 'units';

    public const UNIT_TYPES = [
        self::UNIT_TYPE_SQFT,
        self::UNIT_TYPE_LNFT,
        self::UNIT_TYPE_UNITS,
    ];

    protected $fillable = [
        'estimate_template_id',
        'name',
        'surface_type',
        'calculation_method',
        'calculation_value',
        'unit_type',
        'production_rate_id',
        'default_coats',
        'is_required',
        'is_default_enabled',
        'fixed_price',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'calculation_value' => 'decimal:4',
            'default_coats' => 'integer',
            'is_required' => 'boolean',
            'is_default_enabled' => 'boolean',
            'fixed_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the template this surface belongs to.
     */
    public function estimateTemplate(): BelongsTo
    {
        return $this->belongsTo(EstimateTemplate::class);
    }

    /**
     * Get the production rate for this surface.
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * Scope to only include required surfaces.
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('is_required', true);
    }

    /**
     * Scope to only include optional surfaces.
     */
    public function scopeOptional(Builder $query): Builder
    {
        return $query->where('is_required', false);
    }

    /**
     * Scope to only include enabled by default surfaces.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_default_enabled', true);
    }

    /**
     * Scope to order by sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Calculate the quantity for this surface based on room sqft.
     *
     * @param  float  $roomSqft  The room's square footage
     * @return float The calculated quantity in the surface's unit type
     */
    public function calculateQuantity(float $roomSqft): float
    {
        return match ($this->calculation_method) {
            self::CALC_METHOD_RATIO => $roomSqft * (float) $this->calculation_value,
            self::CALC_METHOD_PER_100SQFT => ($roomSqft / 100) * (float) $this->calculation_value,
            self::CALC_METHOD_PER_LNFT => ($roomSqft / 100) * (float) $this->calculation_value,
            self::CALC_METHOD_FIXED_COUNT => (float) $this->calculation_value,
            self::CALC_METHOD_FIXED_PRICE => 1.0, // Fixed price, quantity is always 1
            default => 0.0,
        };
    }

    /**
     * Get the human-readable surface type label.
     */
    public function getSurfaceTypeLabelAttribute(): string
    {
        return match ($this->surface_type) {
            self::SURFACE_TYPE_WALLS => 'Walls',
            self::SURFACE_TYPE_CEILING => 'Ceiling',
            self::SURFACE_TYPE_TRIM => 'Trim/Baseboards',
            self::SURFACE_TYPE_DOORS => 'Doors',
            self::SURFACE_TYPE_WINDOWS => 'Windows',
            self::SURFACE_TYPE_FLOOR => 'Floor',
            self::SURFACE_TYPE_OTHER => 'Other',
            default => ucfirst($this->surface_type ?? ''),
        };
    }

    /**
     * Get the human-readable calculation method label.
     */
    public function getCalculationMethodLabelAttribute(): string
    {
        return match ($this->calculation_method) {
            self::CALC_METHOD_RATIO => 'Ratio of Room',
            self::CALC_METHOD_PER_100SQFT => 'Per 100 sqft',
            self::CALC_METHOD_PER_LNFT => 'Linear Feet',
            self::CALC_METHOD_FIXED_COUNT => 'Fixed Count',
            self::CALC_METHOD_FIXED_PRICE => 'Fixed Price',
            default => ucfirst($this->calculation_method ?? ''),
        };
    }

    /**
     * Get the human-readable unit type label.
     */
    public function getUnitTypeLabelAttribute(): string
    {
        return match ($this->unit_type) {
            self::UNIT_TYPE_SQFT => 'sqft',
            self::UNIT_TYPE_LNFT => 'lnft',
            self::UNIT_TYPE_UNITS => 'units',
            default => $this->unit_type ?? '',
        };
    }
}
