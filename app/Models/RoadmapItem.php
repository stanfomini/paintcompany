<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoadmapItem extends Model
{
    /** @use HasFactory<\Database\Factories\RoadmapItemFactory> */
    use HasFactory;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUSES = [
        self::STATUS_NOT_STARTED => 'Not Started',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_DONE => 'Done',
    ];

    protected $fillable = [
        'roadmap_category_id',
        'title',
        'description',
        'status',
        'notes',
        'assigned_to',
        'due_date',
        'completed_at',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'date',
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(RoadmapCategory::class, 'roadmap_category_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_DONE => 'green',
            self::STATUS_IN_PROGRESS => 'blue',
            default => 'zinc',
        };
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isOverdue(): bool
    {
        return $this->due_date && $this->due_date->isPast() && ! $this->isDone();
    }

    public function markAsDone(): bool
    {
        $this->status = self::STATUS_DONE;
        $this->completed_at = now();

        return $this->save();
    }

    public function markAsInProgress(): bool
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->completed_at = null;

        return $this->save();
    }

    public function markAsNotStarted(): bool
    {
        $this->status = self::STATUS_NOT_STARTED;
        $this->completed_at = null;

        return $this->save();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public function scopeIncomplete($query)
    {
        return $query->where('status', '!=', self::STATUS_DONE);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_DONE);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->where('status', '!=', self::STATUS_DONE);
    }
}
