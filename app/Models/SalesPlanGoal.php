<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesPlanGoal extends Model
{
    /** @use HasFactory<\Database\Factories\SalesPlanGoalFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'year',
        'annual_revenue_goal',
        'avg_job_size',
        'lead_conversion_rate',
        'estimate_close_rate',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'annual_revenue_goal' => 'decimal:2',
            'avg_job_size' => 'decimal:2',
            'lead_conversion_rate' => 'decimal:2',
            'estimate_close_rate' => 'decimal:2',
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
     * Calculate required jobs to hit annual revenue goal.
     */
    public function getRequiredJobsAttribute(): int
    {
        if ($this->avg_job_size <= 0) {
            return 0;
        }

        return (int) ceil($this->annual_revenue_goal / $this->avg_job_size);
    }

    /**
     * Calculate required leads to hit annual revenue goal.
     */
    public function getRequiredLeadsAttribute(): int
    {
        if ($this->lead_conversion_rate <= 0 || $this->estimate_close_rate <= 0) {
            return 0;
        }

        $requiredJobs = $this->required_jobs;
        $requiredEstimates = $requiredJobs / ($this->estimate_close_rate / 100);
        $requiredLeads = $requiredEstimates / ($this->lead_conversion_rate / 100);

        return (int) ceil($requiredLeads);
    }

    /**
     * Calculate weekly targets based on annual goal.
     *
     * @return array{leads: int, estimates: int, jobs: int, revenue: float}
     */
    public function getWeeklyTargetsAttribute(): array
    {
        $weeks = 52;

        return [
            'leads' => (int) ceil($this->required_leads / $weeks),
            'estimates' => (int) ceil($this->required_jobs / ($this->estimate_close_rate / 100) / $weeks),
            'jobs' => (int) ceil($this->required_jobs / $weeks),
            'revenue' => round($this->annual_revenue_goal / $weeks, 2),
        ];
    }
}
