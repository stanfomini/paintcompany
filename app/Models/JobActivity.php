<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobActivity extends Model
{
    /** @use HasFactory<\Database\Factories\JobActivityFactory> */
    use HasFactory;

    public const ACTIVITY_TYPES = [
        'stage_changed' => 'Stage Changed',
        'date_proposed' => 'Date Proposed',
        'date_confirmed_company' => 'Company Confirmed Date',
        'date_confirmed_customer' => 'Customer Confirmed Date',
        'colors_confirmed_company' => 'Company Confirmed Colors',
        'colors_confirmed_customer' => 'Customer Confirmed Colors',
        'color_added' => 'Color Added',
        'crew_dispatched' => 'Crew Dispatched',
        'crew_accepted' => 'Crew Accepted',
        'crew_rejected' => 'Crew Rejected',
        'completed' => 'Completed',
        'prep_plan_created' => 'Prep Plan Created',
        'prep_plan_changed' => 'Prep Plan Changed',
        'prep_task_added' => 'Prep Task Added',
        'prep_task_removed' => 'Prep Task Removed',
        'prep_task_completed' => 'Prep Task Completed',
        'prep_task_moved' => 'Prep Task Moved',
        'prep_approved_team' => 'Team Approved Prep Plan',
        'prep_approved_crew' => 'Crew Approved Prep Plan',
        'prep_approved_customer' => 'Customer Approved Prep Plan',
        'prep_approvals_reset' => 'Prep Approvals Reset',
        'job_started' => 'Job Started',
    ];

    protected $fillable = [
        'job_id',
        'user_id',
        'activity_type',
        'description',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * Get the activity type label.
     */
    public function getActivityTypeLabelAttribute(): string
    {
        return self::ACTIVITY_TYPES[$this->activity_type] ?? $this->activity_type;
    }

    /**
     * Get the job for this activity.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Get the user who performed this activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Log an activity for a job.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public static function log(
        Job $job,
        string $type,
        ?string $description = null,
        ?array $metadata = null
    ): self {
        return static::create([
            'job_id' => $job->id,
            'user_id' => auth()->id(),
            'activity_type' => $type,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => request()->ip(),
        ]);
    }

    /**
     * Scope to filter by activity type.
     */
    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('activity_type', $type);
    }
}
