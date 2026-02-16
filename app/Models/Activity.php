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

    public const MEETING_TYPES = [
        'in_person' => 'In Person',
        'virtual' => 'Virtual',
    ];

    public const MEETING_STATUSES = [
        'proposed' => 'Proposed',
        'confirmed' => 'Confirmed',
        'declined' => 'Declined',
        'cancelled' => 'Cancelled',
    ];

    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    public const RECURRENCE_RULES = [
        'daily' => 'Daily',
        'weekdays' => 'Weekdays (Mon-Fri)',
        'weekly' => 'Weekly',
        'biweekly' => 'Every 2 Weeks',
        'monthly' => 'Monthly',
    ];

    protected $fillable = [
        'user_id',
        'assigned_to',
        'subject_type',
        'subject_id',
        'type',
        'title',
        'description',
        'is_completed',
        'due_at',
        'ends_at',
        'completed_at',
        'automation_key',
        'priority',
        'priority_score',
        'snoozed_until',
        'recurrence_rule',
        'recurrence_parent_id',
        'meeting_type',
        'meeting_status',
        'proposed_by',
        'video_call_id',
        'estimate_id',
        'customer_confirmed_at',
        'agent_confirmed_at',
        'team_confirmed_at',
        'reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'due_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'priority_score' => 'integer',
            'customer_confirmed_at' => 'datetime',
            'agent_confirmed_at' => 'datetime',
            'team_confirmed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Get the type label.
     */
    public function getTypeLabelAttribute(): string
    {
        return __(self::TYPES[$this->type] ?? $this->type);
    }

    /**
     * Get the user who created this activity.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user assigned to this activity.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the subject (Deal or Customer).
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the user who proposed this meeting.
     */
    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    /**
     * Get the video call for this meeting.
     */
    public function videoCall(): BelongsTo
    {
        return $this->belongsTo(VideoCall::class);
    }

    /**
     * Get the estimate linked to this meeting.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Check if this activity is a meeting.
     */
    public function isMeeting(): bool
    {
        return $this->type === 'meeting' && $this->meeting_type !== null;
    }

    /**
     * Check if this is a virtual meeting.
     */
    public function isVirtualMeeting(): bool
    {
        return $this->isMeeting() && $this->meeting_type === 'virtual';
    }

    /**
     * Check if the meeting is fully confirmed by all required parties.
     */
    public function isFullyConfirmed(?Deal $deal = null): bool
    {
        if (! $this->isMeeting()) {
            return false;
        }

        $deal = $deal ?? ($this->subject instanceof Deal ? $this->subject : null);
        if (! $deal) {
            return false;
        }

        if (! $this->team_confirmed_at) {
            return false;
        }

        if ($deal->customer_id && ! $this->customer_confirmed_at) {
            return false;
        }

        if ($deal->agent_id && ! $this->agent_confirmed_at) {
            return false;
        }

        return true;
    }

    /**
     * Check if the meeting needs confirmation from a specific party.
     */
    public function needsConfirmationFrom(string $party): bool
    {
        return match ($party) {
            'customer' => $this->meeting_status === 'proposed' && ! $this->customer_confirmed_at,
            'agent' => $this->meeting_status === 'proposed' && ! $this->agent_confirmed_at,
            'team' => $this->meeting_status === 'proposed' && ! $this->team_confirmed_at,
            default => false,
        };
    }

    /**
     * Mark the activity as completed.
     */
    public function markAsCompleted(): bool
    {
        $this->is_completed = true;
        $this->completed_at = now();

        $saved = $this->save();

        if ($saved && $this->isRecurring()) {
            $this->createNextOccurrence();
        }

        return $saved;
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
     * Scope to filter by user (creator).
     */
    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * Scope to filter by assignee.
     */
    public function scopeAssignedTo($query, User $user)
    {
        return $query->where('assigned_to', $user->id);
    }

    /**
     * Scope to filter activities assigned to or created by the user.
     */
    public function scopeForUserOrAssignedTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('assigned_to', $user->id);
        });
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

    /**
     * Scope to filter by automation key.
     */
    public function scopeByAutomationKey($query, string $key)
    {
        return $query->where('automation_key', $key);
    }

    /**
     * Scope to filter by polymorphic subject.
     */
    public function scopeForSubject($query, Model $subject)
    {
        return $query->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());
    }

    /**
     * Scope for upcoming meetings that are confirmed and not completed.
     */
    public function scopeUpcomingMeetings($query)
    {
        return $query->where('type', 'meeting')
            ->whereNotNull('meeting_type')
            ->where('meeting_status', 'confirmed')
            ->where('is_completed', false)
            ->whereNotNull('due_at')
            ->where('due_at', '>=', now())
            ->orderBy('due_at');
    }

    /**
     * Scope for meetings that need a reminder sent.
     */
    public function scopeNeedingReminder($query)
    {
        return $query->where('type', 'meeting')
            ->whereNotNull('meeting_type')
            ->where('meeting_status', 'confirmed')
            ->where('is_completed', false)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now(), now()->addMinutes(65)]);
    }

    public function getSubjectUrl(): ?string
    {
        return match ($this->subject_type) {
            Deal::class => route('deals.show', $this->subject_id),
            Customer::class => route('customers.show', $this->subject_id),
            Job::class => route('jobs.show', $this->subject_id),
            Estimate::class => route('estimates.show', $this->subject_id),
            User::class => match ($this->subject?->type) {
                'agent' => route('agents.show', $this->subject_id),
                'crew' => route('crews.show', $this->subject_id),
                default => null,
            },
            default => null,
        };
    }

    public function getSubjectLabel(): ?string
    {
        return match ($this->subject_type) {
            Deal::class => $this->subject?->customer?->full_name ?? $this->subject?->title,
            Customer::class => $this->subject?->full_name ?? 'Customer',
            Job::class => $this->subject?->title ?? 'Job',
            Estimate::class => $this->subject?->title ?? $this->subject?->estimate_number ?? 'Estimate',
            User::class => $this->subject?->name ?? 'User',
            default => null,
        };
    }

    /**
     * Get the parent recurring activity.
     */
    public function recurrenceParent(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'recurrence_parent_id');
    }

    /**
     * Get child occurrences of this recurring activity.
     */
    public function recurrenceChildren(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Activity::class, 'recurrence_parent_id');
    }

    /**
     * Check if this activity is recurring.
     */
    public function isRecurring(): bool
    {
        return $this->recurrence_rule !== null;
    }

    /**
     * Create the next occurrence of a recurring activity.
     */
    public function createNextOccurrence(): ?self
    {
        if (! $this->isRecurring() || ! $this->due_at) {
            return null;
        }

        $nextDue = $this->calculateNextDueDate();

        if (! $nextDue) {
            return null;
        }

        $parentId = $this->recurrence_parent_id ?? $this->id;

        $nextEndsAt = null;
        if ($this->ends_at && $this->due_at) {
            $duration = $this->due_at->diffInSeconds($this->ends_at);
            $nextEndsAt = $nextDue->copy()->addSeconds($duration);
        }

        return static::create([
            'user_id' => $this->user_id,
            'assigned_to' => $this->assigned_to,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'type' => $this->type,
            'title' => $this->title,
            'description' => $this->description,
            'is_completed' => false,
            'due_at' => $nextDue,
            'ends_at' => $nextEndsAt,
            'automation_key' => $this->automation_key,
            'priority' => $this->priority ?? 'normal',
            'recurrence_rule' => $this->recurrence_rule,
            'recurrence_parent_id' => $parentId,
        ]);
    }

    /**
     * Calculate the next due date based on recurrence rule.
     */
    public function calculateNextDueDate(): ?\Carbon\CarbonInterface
    {
        if (! $this->due_at || ! $this->recurrence_rule) {
            return null;
        }

        $base = $this->due_at->copy();

        return match ($this->recurrence_rule) {
            'daily' => $base->addDay(),
            'weekdays' => $this->nextWeekday($base),
            'weekly' => $base->addWeek(),
            'biweekly' => $base->addWeeks(2),
            'monthly' => $base->addMonth(),
            default => null,
        };
    }

    /**
     * Get the next weekday after a given date.
     */
    protected function nextWeekday(\Carbon\CarbonInterface $date): \Carbon\CarbonInterface
    {
        $next = $date->copy()->addDay();
        while ($next->isWeekend()) {
            $next = $next->addDay();
        }

        return $next;
    }

    /**
     * Scope to filter by priority.
     */
    public function scopeOfPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    /**
     * Scope to exclude snoozed activities.
     */
    public function scopeNotSnoozed($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('snoozed_until')
                ->orWhere('snoozed_until', '<=', now());
        });
    }

    /**
     * Scope for smart inbox: incomplete, not snoozed, ordered by priority score.
     */
    public function scopeSmartInbox($query)
    {
        return $query->incomplete()
            ->notSnoozed()
            ->orderByDesc('priority_score');
    }

    /**
     * Check if the activity is currently snoozed.
     */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until && $this->snoozed_until->isFuture();
    }

    /**
     * Snooze this activity until a given time.
     */
    public function snoozeUntil(\DateTimeInterface $until): bool
    {
        $this->snoozed_until = $until;

        return $this->save();
    }
}
