<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPrepTask extends Model
{
    /** @use HasFactory<\Database\Factories\JobPrepTaskFactory> */
    use HasFactory;

    public const TYPE_SURFACE = 'surface';

    public const TYPE_ADDON = 'addon';

    public const TYPE_CUSTOM = 'custom';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'job_prep_day_id',
        'job_prep_plan_id',
        'service_category_id',
        'estimate_area_id',
        'estimate_area_surface_id',
        'estimate_area_addon_id',
        'task_type',
        'title',
        'description',
        'area_name',
        'surface_name',
        'quantity',
        'unit',
        'coats',
        'estimated_hours',
        'status',
        'completed_by',
        'completed_at',
        'completion_notes',
        'sort_order',
        'added_by',
        'removal_requested_by',
        'removal_requested_at',
        'removal_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'coats' => 'integer',
            'estimated_hours' => 'float',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
            'removal_requested_at' => 'datetime',
        ];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(JobPrepDay::class, 'job_prep_day_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(JobPrepPlan::class, 'job_prep_plan_id');
    }

    public function estimateArea(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class);
    }

    public function estimateAreaSurface(): BelongsTo
    {
        return $this->belongsTo(EstimateAreaSurface::class);
    }

    public function estimateAreaAddon(): BelongsTo
    {
        return $this->belongsTo(EstimateAreaAddon::class);
    }

    public function completedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Mark task as completed.
     */
    public function markComplete(User $user, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_by' => $user->id,
            'completed_at' => now(),
            'completion_notes' => $notes,
        ]);
    }

    /**
     * Mark task as in progress.
     */
    public function markInProgress(): void
    {
        $this->update([
            'status' => self::STATUS_IN_PROGRESS,
            'completed_by' => null,
            'completed_at' => null,
            'completion_notes' => null,
        ]);
    }

    /**
     * Revert task to pending.
     */
    public function revertToPending(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'completed_by' => null,
            'completed_at' => null,
            'completion_notes' => null,
        ]);
    }

    /**
     * Check if this task is linked to an estimate surface or addon.
     */
    public function isFromEstimate(): bool
    {
        return $this->task_type === self::TYPE_SURFACE || $this->task_type === self::TYPE_ADDON;
    }

    /**
     * Check if this is a custom (manually added) task.
     */
    public function isCustom(): bool
    {
        return $this->task_type === self::TYPE_CUSTOM;
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function addedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }

    public function removalRequestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removal_requested_by');
    }

    public function hasRemovalRequest(): bool
    {
        return $this->removal_requested_by !== null;
    }

    public function requestRemoval(User $requester, ?string $reason = null): void
    {
        $this->update([
            'removal_requested_by' => $requester->id,
            'removal_requested_at' => now(),
            'removal_reason' => $reason,
        ]);
    }

    public function dismissRemovalRequest(): void
    {
        $this->update([
            'removal_requested_by' => null,
            'removal_requested_at' => null,
            'removal_reason' => null,
        ]);
    }
}
