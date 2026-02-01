<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateAreaPaintSelection extends Model
{
    use HasFactory;

    public const SURFACE_WALLS = 'walls';

    public const SURFACE_CEILING = 'ceiling';

    public const SURFACE_TRIM = 'trim';

    public const SURFACE_DOORS = 'doors';

    public const SURFACE_WINDOWS = 'windows';

    public const SURFACE_CABINETS = 'cabinets';

    public const SURFACE_OTHER = 'other';

    protected $fillable = [
        'estimate_area_id',
        'paint_product_id',
        'surface_type',
        'coats',
        'sqft_covered',
        'gallons_needed',
        'paint_cost',
        'recycle_fee',
        'total_material_cost',
        'color_name',
        'color_code',
    ];

    protected function casts(): array
    {
        return [
            'coats' => 'integer',
            'sqft_covered' => 'decimal:2',
            'gallons_needed' => 'decimal:2',
            'paint_cost' => 'decimal:2',
            'recycle_fee' => 'decimal:2',
            'total_material_cost' => 'decimal:2',
        ];
    }

    /**
     * Get the estimate area.
     */
    public function estimateArea(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class);
    }

    /**
     * Get the paint product.
     */
    public function paintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class);
    }

    /**
     * Calculate and store the paint cost.
     */
    public function calculateCost(float $recycleFeePerGallon = 0.54): void
    {
        if (! $this->paintProduct || $this->sqft_covered <= 0) {
            return;
        }

        $area = $this->estimateArea;
        $applicationMethod = $area?->application_method ?? 'brush_roll';

        $costCalc = $this->paintProduct->calculateCost(
            (float) $this->sqft_covered,
            $this->coats,
            $applicationMethod,
            $recycleFeePerGallon
        );

        $this->gallons_needed = $costCalc['gallons_rounded'];
        $this->paint_cost = $costCalc['paint_cost'];
        $this->recycle_fee = $costCalc['recycle_fee'];
        $this->total_material_cost = $costCalc['total_cost'];
    }

    /**
     * Get the full paint name.
     */
    public function getPaintNameAttribute(): string
    {
        return $this->paintProduct?->full_name ?? 'No Paint Selected';
    }

    /**
     * Get the formatted color display.
     */
    public function getColorDisplayAttribute(): ?string
    {
        if ($this->color_name && $this->color_code) {
            return "{$this->color_name} ({$this->color_code})";
        }

        return $this->color_name ?? $this->color_code;
    }

    /**
     * Get available surface types.
     *
     * @return array<string, string>
     */
    public static function getSurfaceTypes(): array
    {
        return [
            self::SURFACE_WALLS => 'Walls',
            self::SURFACE_CEILING => 'Ceiling',
            self::SURFACE_TRIM => 'Trim',
            self::SURFACE_DOORS => 'Doors',
            self::SURFACE_WINDOWS => 'Windows',
            self::SURFACE_CABINETS => 'Cabinets',
            self::SURFACE_OTHER => 'Other',
        ];
    }
}
