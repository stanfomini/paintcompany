<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiActual extends Model
{
    /** @use HasFactory<\Database\Factories\KpiActualFactory> */
    use HasFactory;

    protected $fillable = [
        'kpi_metric_id',
        'actual_value',
        'period_start',
        'period_end',
        'is_manual',
        'entered_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'actual_value' => 'decimal:2',
            'period_start' => 'date',
            'period_end' => 'date',
            'is_manual' => 'boolean',
        ];
    }

    public function metric(): BelongsTo
    {
        return $this->belongsTo(KpiMetric::class, 'kpi_metric_id');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function getFormattedValueAttribute(): string
    {
        return $this->metric->formatValue($this->actual_value);
    }

    public function isOnTrack(): bool
    {
        return $this->metric->isOnTrack($this->actual_value);
    }

    public function getVarianceAttribute(): ?float
    {
        return $this->metric->getVariance($this->actual_value);
    }

    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    public function scopeCalculated($query)
    {
        return $query->where('is_manual', false);
    }
}
