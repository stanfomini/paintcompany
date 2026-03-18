<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NestedTemplateSurface extends Model
{
    protected $fillable = [
        'nested_area_template_id',
        'name',
        'surface_type',
        'calculation_method',
        'calculation_value',
        'unit_type',
        'production_rate_id',
        'default_coats',
        'is_required',
        'is_default_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'calculation_value' => 'decimal:4',
            'default_coats' => 'integer',
            'is_required' => 'boolean',
            'is_default_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function nestedAreaTemplate(): BelongsTo
    {
        return $this->belongsTo(NestedAreaTemplate::class);
    }

    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }
}
