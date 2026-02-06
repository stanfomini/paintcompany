<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a business cost allocation (marketing, sales, loans, etc.).
 *
 * Allocations are deducted from gross profit to calculate net profit.
 * Three calculation types are supported:
 * - percent_of_revenue: A percentage of the customer price
 * - fixed_per_job: A fixed dollar amount per job
 * - per_labor_hour: A dollar amount per labor hour worked
 */
class CompanyAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\CompanyAllocationFactory> */
    use HasFactory;

    public const TYPE_PERCENT_OF_REVENUE = 'percent_of_revenue';

    public const TYPE_FIXED_PER_JOB = 'fixed_per_job';

    public const TYPE_PER_LABOR_HOUR = 'per_labor_hour';

    protected $fillable = [
        'company_setting_id',
        'name',
        'slug',
        'type',
        'value',
        'is_active',
        'is_system',
        'is_informational',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'is_informational' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the company settings this allocation belongs to.
     */
    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    /**
     * Scope to active allocations only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to allocations that reduce profit (not informational).
     */
    public function scopeReducesProfit(Builder $query): Builder
    {
        return $query->where('is_informational', false);
    }

    /**
     * Scope to system allocations only.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope to custom (non-system) allocations.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Calculate the allocation amount for a given job.
     *
     * @param  float  $customerPrice  Total customer price (revenue)
     * @param  float  $laborHours  Total labor hours worked
     */
    public function calculateAmount(float $customerPrice, float $laborHours = 0): float
    {
        if (! $this->is_active) {
            return 0.0;
        }

        return match ($this->type) {
            self::TYPE_PERCENT_OF_REVENUE => round($customerPrice * ((float) $this->value / 100), 2),
            self::TYPE_FIXED_PER_JOB => round((float) $this->value, 2),
            self::TYPE_PER_LABOR_HOUR => round($laborHours * (float) $this->value, 2),
            default => 0.0,
        };
    }

    /**
     * Get the type label for display.
     */
    public function getTypeLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PERCENT_OF_REVENUE => 'Percent of Revenue',
            self::TYPE_FIXED_PER_JOB => 'Fixed per Job',
            self::TYPE_PER_LABOR_HOUR => 'Per Labor Hour',
            default => 'Unknown',
        };
    }

    /**
     * Get the formatted value for display.
     */
    public function getFormattedValueAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_PERCENT_OF_REVENUE => number_format((float) $this->value, 2).'%',
            self::TYPE_FIXED_PER_JOB => '$'.number_format((float) $this->value, 2),
            self::TYPE_PER_LABOR_HOUR => '$'.number_format((float) $this->value, 2).'/hr',
            default => (string) $this->value,
        };
    }

    /**
     * Check if this allocation can be deleted.
     */
    public function canBeDeleted(): bool
    {
        return ! $this->is_system;
    }

    /**
     * Get available allocation types.
     *
     * @return array<string, string>
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_PERCENT_OF_REVENUE => 'Percent of Revenue',
            self::TYPE_FIXED_PER_JOB => 'Fixed per Job',
            self::TYPE_PER_LABOR_HOUR => 'Per Labor Hour',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::deleting(function (CompanyAllocation $allocation) {
            if ($allocation->is_system) {
                throw new \RuntimeException('System allocations cannot be deleted.');
            }
        });
    }
}
