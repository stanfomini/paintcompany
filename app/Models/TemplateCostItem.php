<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TemplateCostItem extends Model
{
    protected $fillable = [
        'costable_type',
        'costable_id',
        'name',
        'cost_type',
        'default_quantity',
        'quantity_calculation',
        'quantity_value',
        'unit',
        'unit_cost',
        'unit_price',
        'tier',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_quantity' => 'decimal:2',
            'quantity_value' => 'decimal:4',
            'unit_cost' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function costable(): MorphTo
    {
        return $this->morphTo();
    }
}
