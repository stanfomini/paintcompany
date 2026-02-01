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
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
            'sort_order' => 'integer',
        ];
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
            $item->estimate->calculateTotals();
            $item->estimate->save();
        });

        static::deleted(function (EstimateItem $item) {
            $item->estimate->calculateTotals();
            $item->estimate->save();
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
