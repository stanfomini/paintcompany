<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPlanWeek extends Model
{
    /** @use HasFactory<\Database\Factories\SalesPlanWeekFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'year',
        'week_number',
        'leads_plan',
        'leads_actual',
        'estimates_plan',
        'estimates_actual',
        'jobs_booked_plan',
        'jobs_booked_actual',
        'revenue_plan',
        'revenue_actual',
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
            'leads_plan' => 'integer',
            'leads_actual' => 'integer',
            'estimates_plan' => 'integer',
            'estimates_actual' => 'integer',
            'jobs_booked_plan' => 'integer',
            'jobs_booked_actual' => 'integer',
            'revenue_plan' => 'decimal:2',
            'revenue_actual' => 'decimal:2',
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
     * @param  Builder<SalesPlanWeek>  $query
     * @return Builder<SalesPlanWeek>
     */
    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }

    /**
     * Scope to a specific week.
     *
     * @param  Builder<SalesPlanWeek>  $query
     * @return Builder<SalesPlanWeek>
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
     * Get the formatted week label (e.g., "Week 5 - Jan 27 - Feb 2").
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
     * Calculate leads variance (actual - plan).
     */
    public function getLeadsVarianceAttribute(): int
    {
        return $this->leads_actual - $this->leads_plan;
    }

    /**
     * Calculate estimates variance.
     */
    public function getEstimatesVarianceAttribute(): int
    {
        return $this->estimates_actual - $this->estimates_plan;
    }

    /**
     * Calculate jobs booked variance.
     */
    public function getJobsBookedVarianceAttribute(): int
    {
        return $this->jobs_booked_actual - $this->jobs_booked_plan;
    }

    /**
     * Calculate revenue variance.
     */
    public function getRevenueVarianceAttribute(): float
    {
        return (float) $this->revenue_actual - (float) $this->revenue_plan;
    }

    /**
     * Check if actuals meet or exceed plan.
     */
    public function getMeetsTargetAttribute(): bool
    {
        return $this->leads_actual >= $this->leads_plan
            && $this->estimates_actual >= $this->estimates_plan
            && $this->jobs_booked_actual >= $this->jobs_booked_plan;
    }
}
