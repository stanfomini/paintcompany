<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPrepPlan extends Model
{
    /** @use HasFactory<\Database\Factories\JobPrepPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'job_id',
        'crew_size',
        'hours_per_day',
        'total_days',
        'total_labor_hours',
        'total_cleanup_hours',
        'team_approved',
        'crew_approved',
        'customer_approved',
        'team_approved_at',
        'crew_approved_at',
        'customer_approved_at',
        'team_approved_by',
        'crew_approved_by',
        'customer_approved_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'crew_size' => 'integer',
            'hours_per_day' => 'float',
            'total_days' => 'integer',
            'total_labor_hours' => 'float',
            'total_cleanup_hours' => 'float',
            'team_approved' => 'boolean',
            'crew_approved' => 'boolean',
            'customer_approved' => 'boolean',
            'team_approved_at' => 'datetime',
            'crew_approved_at' => 'datetime',
            'customer_approved_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(JobPrepDay::class)->orderBy('day_number');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(JobPrepTask::class);
    }

    public function teamApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'team_approved_by');
    }

    public function crewApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'crew_approved_by');
    }

    public function customerApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_approved_by');
    }

    /**
     * Check if all three parties have approved.
     */
    public function isFullyApproved(): bool
    {
        return $this->team_approved && $this->crew_approved && $this->customer_approved;
    }

    /**
     * Get overall task completion percentage.
     */
    public function getProgressPercent(): float
    {
        $total = $this->tasks()->count();

        if ($total === 0) {
            return 0.0;
        }

        $completed = $this->tasks()->where('status', JobPrepTask::STATUS_COMPLETED)->count();

        return round(($completed / $total) * 100, 1);
    }

    /**
     * Calculate total days needed based on crew size and hours per day.
     */
    public function calculateTotalDays(): int
    {
        $totalHours = $this->total_labor_hours + $this->total_cleanup_hours;

        if ($totalHours <= 0 || $this->crew_size <= 0 || $this->hours_per_day <= 0) {
            return 1;
        }

        return max(1, (int) ceil($totalHours / $this->crew_size / $this->hours_per_day));
    }
}
