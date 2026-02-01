<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateOption extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateOptionFactory> */
    use HasFactory;

    public const PRICING_TYPES = [
        'fixed' => 'Fixed Price',
        'per_sqft' => 'Per Square Foot',
        'per_surface' => 'Per Surface',
    ];

    protected $fillable = [
        'estimate_id',
        'surface_id',
        'name',
        'description',
        'pricing_type',
        'fixed_price',
        'multiplier',
        'calculated_price',
        'is_selected',
        'is_recommended',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'fixed_price' => 'decimal:2',
            'multiplier' => 'decimal:3',
            'calculated_price' => 'decimal:2',
            'is_selected' => 'boolean',
            'is_recommended' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateOption $option) {
            $option->calculated_price = $option->calculatePrice();
        });
    }

    /**
     * Calculate the price based on pricing type.
     */
    public function calculatePrice(): float
    {
        return match ($this->pricing_type) {
            'fixed' => (float) ($this->fixed_price ?? 0),
            'per_sqft' => $this->calculatePerSquareFoot(),
            'per_surface' => $this->calculatePerSurface(),
            default => 0,
        };
    }

    /**
     * Calculate price per square foot.
     */
    protected function calculatePerSquareFoot(): float
    {
        if (! $this->surface || ! $this->multiplier) {
            return (float) ($this->fixed_price ?? 0);
        }

        $squareFootage = $this->surface->square_footage ?? 0;

        return (float) ($squareFootage * $this->multiplier);
    }

    /**
     * Calculate price per surface.
     */
    protected function calculatePerSurface(): float
    {
        if (! $this->surface || ! $this->multiplier) {
            return (float) ($this->fixed_price ?? 0);
        }

        $basePrice = $this->surface->base_price ?? 0;

        return (float) ($basePrice * $this->multiplier);
    }

    /**
     * Get the estimate for this option.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the surface for this option (nullable).
     */
    public function surface(): BelongsTo
    {
        return $this->belongsTo(EstimateAreaSurface::class, 'surface_id');
    }

    /**
     * Scope to filter selected options.
     */
    public function scopeSelected(Builder $query): Builder
    {
        return $query->where('is_selected', true);
    }

    /**
     * Scope to filter recommended options.
     */
    public function scopeRecommended(Builder $query): Builder
    {
        return $query->where('is_recommended', true);
    }
}
