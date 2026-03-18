<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EstimateCostItem extends Model
{
    protected $fillable = [
        'costable_type',
        'costable_id',
        'name',
        'cost_type',
        'quantity',
        'unit',
        'unit_cost',
        'unit_price',
        'total_cost',
        'total_price',
        'tier',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EstimateCostItem $item) {
            $item->total_cost = (float) $item->quantity * (float) $item->unit_cost;
            $item->total_price = (float) $item->quantity * (float) $item->unit_price;
        });
    }

    public function costable(): MorphTo
    {
        return $this->morphTo();
    }
}
