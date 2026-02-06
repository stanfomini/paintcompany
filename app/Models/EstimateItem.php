<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateItem extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateItemFactory> */
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'description',
        'details',
        'quantity',
        'unit',
        'unit_price',
        'total',
        'cost',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'cost' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the vendor/internal cost (defaults to total if not set).
     */
    public function getCostAttribute($value): float
    {
        return (float) ($value ?? $this->total);
    }

    /**
     * Get the profit on this item (customer price - vendor cost).
     */
    public function getProfitAttribute(): float
    {
        return (float) $this->total - $this->cost;
    }

    /**
     * Get the profit margin percentage.
     */
    public function getProfitMarginPercentAttribute(): float
    {
        if ((float) $this->total <= 0) {
            return 0;
        }

        return ($this->profit / (float) $this->total) * 100;
    }

    /**
     * Check if this item has vendor cost tracking (cost is different from total).
     */
    public function getHasVendorCostAttribute(): bool
    {
        return $this->attributes['cost'] !== null && (float) $this->attributes['cost'] !== (float) $this->total;
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateItem $item) {
            $item->total = $item->quantity * $item->unit_price;
        });

        static::saved(function (EstimateItem $item) {
            $estimate = $item->estimate;

            // Only recalculate if the estimate wasn't recently updated (within last second)
            // This prevents the save flow from double-calculating during package generation
            $shouldRecalculate = $estimate->updated_at && $estimate->updated_at->lt(now()->subSecond());

            if ($shouldRecalculate) {
                $estimate->calculateTotals();
                $estimate->save();

                // Recalculate package totals since items affect package pricing via effective_price
                foreach ($estimate->packages as $package) {
                    $package->calculateTotals();
                    $package->save();
                }
            } else {
                // Even if we skip full recalc, still update estimate totals
                $estimate->calculateTotals();
                $estimate->save();
            }
        });

        static::deleted(function (EstimateItem $item) {
            $estimate = $item->estimate;

            if ($estimate && $estimate->exists) {
                $estimate->calculateTotals();
                $estimate->save();

                // Recalculate package totals since items affect package pricing
                foreach ($estimate->packages as $package) {
                    $package->calculateTotals();
                    $package->save();
                }
            }
        });
    }

    /**
     * Get the estimate for this item.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }
}
