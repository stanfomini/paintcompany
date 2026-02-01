<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Activity extends Model
{
    /** @use HasFactory<\Database\Factories\ActivityFactory> */
    use HasFactory;

    public const TYPES = [
        'task' => 'Task',
        'note' => 'Note',
        'call' => 'Call',
        'email' => 'Email',
        'meeting' => 'Meeting',
        'other' => 'Other',
    ];

    protected $fillable = [
        'user_id',
        'subject_type',
        'subject_id',
        'type',
        'title',
        'description',
        'is_completed',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /**
     * Get the user who created this activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the subject (Deal or Customer).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Mark the activity as completed.
     */
    public function markAsCompleted(): bool
    {
        $this->is_completed = true;
        $this->completed_at = now();

        return $this->save();
    }

    /**
     * Mark the activity as incomplete.
     */
    public function markAsIncomplete(): bool
    {
        $this->is_completed = false;
        $this->completed_at = null;

        return $this->save();
    }

    /**
     * Check if activity is overdue.
     */
    public function isOverdue(): bool
    {
        return ! $this->is_completed && $this->due_at && $this->due_at->isPast();
    }

    /**
     * Scope to filter by type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter tasks only.
     */
    public function scopeTasks($query)
    {
        return $query->where('type', 'task');
    }

    /**
     * Scope to filter completed activities.
     */
    public function scopeCompleted($query)
    {
        return $query->where('is_completed', true);
    }

    /**
     * Scope to filter incomplete activities.
     */
    public function scopeIncomplete($query)
    {
        return $query->where('is_completed', false);
    }

    /**
     * Scope to filter overdue activities.
     */
    public function scopeOverdue($query)
    {
        return $query->where('is_completed', false)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope to filter activities due today.
     */
    public function scopeDueToday($query)
    {
        return $query->whereDate('due_at', today());
    }

    /**
     * Scope to filter activities due this week.
     */
    public function scopeDueThisWeek($query)
    {
        return $query->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }
}
