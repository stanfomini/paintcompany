<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiTarget extends Model
{
    /** @use HasFactory<\Database\Factories\KpiTargetFactory> */
    use HasFactory;

    protected $fillable = [
        'kpi_metric_id',
        'target_value',
        'period_start',
        'period_end',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(KpiMetric::class, 'kpi_metric_id');
    }

    public function getFormattedValueAttribute(): string
    {
        return $this->metric->formatValue($this->target_value);
    }
}
