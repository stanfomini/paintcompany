<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobPrepDay extends Model
{
    /** @use HasFactory<\Database\Factories\JobPrepDayFactory> */
    use HasFactory;

    protected $fillable = [
        'job_prep_plan_id',
        'day_number',
        'label',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'day_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(JobPrepPlan::class, 'job_prep_plan_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(JobPrepTask::class)->orderBy('sort_order');
    }

    /**
     * Get tasks filtered by service category.
     */
    public function tasksForService(?int $serviceCategoryId): \Illuminate\Database\Eloquent\Collection
    {
        if ($serviceCategoryId === null) {
            return $this->tasks;
        }

        if ($this->relationLoaded('tasks')) {
            return $this->tasks->where('service_category_id', $serviceCategoryId);
        }

        return $this->tasks()->where('service_category_id', $serviceCategoryId)->get();
    }

    /**
     * Get total estimated hours for tasks in this day.
     */
    public function getTotalEstimatedHours(): float
    {
        return (float) $this->tasks()->sum('estimated_hours');
    }

    /**
     * Get count of completed tasks for this day.
     */
    public function getCompletedTasksCount(): int
    {
        return $this->tasks()->where('status', JobPrepTask::STATUS_COMPLETED)->count();
    }

    /**
     * Get completion percentage for this day.
     */
    public function getProgressPercent(): float
    {
        $total = $this->tasks()->count();

        if ($total === 0) {
            return 0.0;
        }

        return round(($this->getCompletedTasksCount() / $total) * 100, 1);
    }
}
