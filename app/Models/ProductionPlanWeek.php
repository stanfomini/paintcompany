<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionPlanWeek extends Model
{
    /** @use HasFactory<\Database\Factories\ProductionPlanWeekFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'year',
        'week_number',
        'revenue_plan',
        'revenue_actual',
        'jobs_completed_plan',
        'jobs_completed_actual',
        'labor_hours_plan',
        'labor_hours_actual',
        'notes',
        'synced_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'week_number' => 'integer',
            'revenue_plan' => 'decimal:2',
            'revenue_actual' => 'decimal:2',
            'jobs_completed_plan' => 'integer',
            'jobs_completed_actual' => 'integer',
            'labor_hours_plan' => 'decimal:2',
            'labor_hours_actual' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CompanySetting, $this>
     */
    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    /**
     * Scope to a specific year.
     *
     * @param  Builder<ProductionPlanWeek>  $query
     * @return Builder<ProductionPlanWeek>
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    /**
     * Scope to a specific week.
     *
     * @param  Builder<ProductionPlanWeek>  $query
     * @return Builder<ProductionPlanWeek>
     */
    public function scopeForWeek(Builder $query, int $week): Builder
    {
        return $query->where('week_number', $week);
    }

    /**
     * Get the week start date.
     */
    public function getWeekStartAttribute(): Carbon
    {
        return Carbon::now()
            ->setISODate($this->year, $this->week_number)
            ->startOfWeek();
    }

    /**
     * Get the week end date.
     */
    public function getWeekEndAttribute(): Carbon
    {
        return Carbon::now()
            ->setISODate($this->year, $this->week_number)
            ->endOfWeek();
    }

    /**
     * Get the formatted week label.
     */
    public function getWeekLabelAttribute(): string
    {
        $start = $this->week_start;
        $end = $this->week_end;

        return sprintf(
            __('Week %d - %s - %s'),
            $this->week_number,
            $start->format('M j'),
            $end->format('M j')
        );
    }

    /**
     * Calculate revenue variance.
     */
    public function getRevenueVarianceAttribute(): float
    {
        return (float) $this->revenue_actual - (float) $this->revenue_plan;
    }

    /**
     * Calculate jobs completed variance.
     */
    public function getJobsCompletedVarianceAttribute(): int
    {
        return $this->jobs_completed_actual - $this->jobs_completed_plan;
    }

    /**
     * Calculate labor hours variance.
     */
    public function getLaborHoursVarianceAttribute(): float
    {
        return (float) $this->labor_hours_actual - (float) $this->labor_hours_plan;
    }

    /**
     * Get revenue as percentage of plan.
     */
    public function getRevenuePercentOfPlanAttribute(): float
    {
        if ($this->revenue_plan <= 0) {
            return $this->revenue_actual > 0 ? 100.0 : 0.0;
        }

        return round(((float) $this->revenue_actual / (float) $this->revenue_plan) * 100, 1);
    }

    /**
     * Check if revenue meets or exceeds plan.
     */
    public function getMeetsTargetAttribute(): bool
    {
        return (float) $this->revenue_actual >= (float) $this->revenue_plan;
    }
}
