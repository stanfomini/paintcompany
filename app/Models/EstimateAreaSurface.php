<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateAreaSurface extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateAreaSurfaceFactory> */
    use HasFactory;

    protected $fillable = [
        'area_id',
        'production_rate_id',
        'name',
        'surface_type',
        'quantity',
        'unit',
        'coats',
        'application_method',
        'paint_brand',
        'paint_type',
        'paint_gallons',
        'paint_cost',
        'production_rate_value',
        'hourly_labor_rate',
        'material_cost_per_unit',
        'labor_hours',
        'labor_cost',
        'material_cost',
        'total',
        'is_included',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'production_rate_value' => 'decimal:2',
            'hourly_labor_rate' => 'decimal:2',
            'material_cost_per_unit' => 'decimal:2',
            'labor_hours' => 'decimal:2',
            'labor_cost' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'paint_gallons' => 'decimal:2',
            'paint_cost' => 'decimal:2',
            'is_included' => 'boolean',
            'coats' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateAreaSurface $surface) {
            // Calculate costs if we have the required values
            if ($surface->quantity && $surface->production_rate_value && $surface->hourly_labor_rate) {
                // Labor hours = quantity / production_rate_value * coats
                $coats = $surface->coats ?? 1;
                $surface->labor_hours = ($surface->quantity / $surface->production_rate_value) * $coats;

                // Labor cost = labor_hours * hourly_labor_rate
                $surface->labor_cost = $surface->labor_hours * $surface->hourly_labor_rate;

                // Material cost = quantity * material_cost_per_unit * coats
                $surface->material_cost = $surface->quantity * ($surface->material_cost_per_unit ?? 0) * $coats;

                // Total = labor_cost + material_cost
                $surface->total = $surface->labor_cost + $surface->material_cost;
            }
        });
    }

    /**
     * Get the area for this surface.
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class, 'area_id');
    }

    /**
     * Get the production rate for this surface.
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * Create a surface from a production rate.
     */
    public static function createFromRate(
        EstimateArea $area,
        ProductionRate $rate,
        float $quantity,
        ?int $coats = null,
        string $applicationMethod = 'brush_roll',
        ?string $paintBrand = null,
        ?string $paintType = null
    ): static {
        $coats = $coats ?? $rate->default_coats ?? 1;

        return static::create([
            'area_id' => $area->id,
            'production_rate_id' => $rate->id,
            'name' => $rate->name,
            'surface_type' => $rate->unit_type,
            'quantity' => $quantity,
            'unit' => $rate->unit_type,
            'coats' => $coats,
            'application_method' => $applicationMethod,
            'paint_brand' => $paintBrand,
            'paint_type' => $paintType,
            'production_rate_value' => $rate->production_rate,
            'hourly_labor_rate' => $rate->hourly_labor_rate,
            'material_cost_per_unit' => $rate->material_cost_per_unit,
            'is_included' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * Calculate and store paint needs for this surface.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total_cost: float}|null
     */
    public function calculatePaintNeeds(?string $brand = null, ?string $type = null): ?array
    {
        $service = app(\App\Services\PaintCalculatorService::class);

        $brand = $brand ?? $this->paint_brand ?? 'sherwin_williams';
        $type = $type ?? $this->paint_type ?? 'superpaint';

        $result = $service->calculatePaintCost(
            (float) $this->quantity,
            $brand,
            $type,
            $this->coats ?? 2,
            $this->application_method ?? 'brush_roll'
        );

        if ($result) {
            $this->paint_gallons = $result['gallons_rounded'];
            $this->paint_cost = $result['total_cost'];
            $this->paint_brand = $brand;
            $this->paint_type = $type;
            $this->save();
        }

        return $result;
    }

    /**
     * Get the application method display name.
     */
    public function getApplicationMethodLabelAttribute(): string
    {
        return match ($this->application_method) {
            'spray' => 'Spray',
            'brush_roll' => 'Brush/Roll',
            default => ucfirst($this->application_method ?? 'brush_roll'),
        };
    }

    /**
     * Get formatted paint brand name.
     */
    public function getPaintBrandLabelAttribute(): ?string
    {
        if (! $this->paint_brand) {
            return null;
        }

        return ucwords(str_replace('_', ' ', $this->paint_brand));
    }

    /**
     * Get formatted paint type name.
     */
    public function getPaintTypeLabelAttribute(): ?string
    {
        if (! $this->paint_type) {
            return null;
        }

        return ucwords(str_replace('_', ' ', $this->paint_type));
    }
}
