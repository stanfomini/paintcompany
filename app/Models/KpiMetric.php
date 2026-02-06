<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KpiMetric extends Model
{
    /** @use HasFactory<\Database\Factories\KpiMetricFactory> */
    use HasFactory;

    public const UNIT_NUMBER = 'number';

    public const UNIT_PERCENT = 'percent';

    public const UNIT_CURRENCY = 'currency';

    public const UNITS = [
        self::UNIT_NUMBER => 'Number',
        self::UNIT_PERCENT => 'Percent',
        self::UNIT_CURRENCY => 'Currency',
    ];

    public const CALC_MANUAL = 'manual';

    public const CALC_AUTO_COUNT = 'auto_count';

    public const CALC_AUTO_SUM = 'auto_sum';

    public const CALC_AUTO_PERCENT = 'auto_percent';

    public const CALC_FORMULA = 'formula';

    public const CALCULATION_TYPES = [
        self::CALC_MANUAL => 'Manual Entry',
        self::CALC_AUTO_COUNT => 'Auto Count',
        self::CALC_AUTO_SUM => 'Auto Sum',
        self::CALC_AUTO_PERCENT => 'Auto Percent',
        self::CALC_FORMULA => 'Formula',
    ];

    public const PERIODS = [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
    ];

    protected $fillable = [
        'kpi_category_id',
        'name',
        'slug',
        'description',
        'unit',
        'calculation_type',
        'calculation_config',
        'target_value',
        'target_period',
        'higher_is_better',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'calculation_config' => 'array',
            'target_value' => 'decimal:2',
            'higher_is_better' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(KpiCategory::class, 'kpi_category_id');
    }

    public function targets(): HasMany
    {
        return $this->hasMany(KpiTarget::class);
    }

    public function actuals(): HasMany
    {
        return $this->hasMany(KpiActual::class);
    }

    public function getTargetForPeriod(Carbon $periodStart, Carbon $periodEnd): ?KpiTarget
    {
        return $this->targets()
            ->where('period_start', '<=', $periodStart)
            ->where('period_end', '>=', $periodEnd)
            ->first();
    }

    public function getActualForPeriod(Carbon $periodStart, Carbon $periodEnd): ?KpiActual
    {
        return $this->actuals()
            ->where('period_start', '>=', $periodStart)
            ->where('period_end', '<=', $periodEnd)
            ->first();
    }

    public function formatValue(?float $value): string
    {
        if ($value === null) {
            return '-';
        }

        return match ($this->unit) {
            self::UNIT_CURRENCY => '$'.number_format($value, 0),
            self::UNIT_PERCENT => number_format($value, 1).'%',
            default => number_format($value, $value == (int) $value ? 0 : 1),
        };
    }

    public function getFormattedTargetAttribute(): string
    {
        return $this->formatValue($this->target_value);
    }

    public function isOnTrack(?float $actual): bool
    {
        if ($actual === null || $this->target_value === null) {
            return false;
        }

        return $this->higher_is_better
            ? $actual >= $this->target_value
            : $actual <= $this->target_value;
    }

    public function getVariance(?float $actual): ?float
    {
        if ($actual === null || $this->target_value === null || $this->target_value == 0) {
            return null;
        }

        return round((($actual - $this->target_value) / $this->target_value) * 100, 1);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
