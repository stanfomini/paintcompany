<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignContact extends Model
{
    use HasFactory;

    protected $table = 'prospector_campaign_contact';

    public $timestamps = false;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'status',
        'is_priority',
        'priority_reason',
        'claimed_by_agent_id',
        'sort_order',
        'attempts',
        'last_attempted_at',
        'last_disposition',
        'added_at',
        'calls_today',
        'total_attempts',
        'entered_at',
        'last_called_at',
        'next_callable_at',
        'exhausted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_priority' => 'boolean',
            'sort_order' => 'integer',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'added_at' => 'datetime',
            'entered_at' => 'datetime',
            'last_called_at' => 'datetime',
            'next_callable_at' => 'datetime',
            'exhausted_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function claimedByAgent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Prospector\Agent::class, 'claimed_by_agent_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeQueued(Builder $query): void
    {
        $query->where('status', 'queued');
    }

    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', 'completed');
    }

    public function scopeNextInQueue(Builder $query): void
    {
        $query->where('status', 'queued')->orderBy('sort_order')->limit(1);
    }

    public function scopeCallbackScheduled(Builder $query): void
    {
        $query->where('status', 'callback_scheduled');
    }

    public function scopePriority(Builder $query): void
    {
        $query->where('is_priority', true);
    }

    public function scopeUnclaimed(Builder $query): void
    {
        $query->whereNull('claimed_by_agent_id');
    }

    public function scopeSmartOrder(Builder $query): void
    {
        $query->orderByDesc('is_priority')->orderBy('sort_order');
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Mark this entry as currently dialing.
     */
    public function markDialing(): void
    {
        $this->update([
            'status' => 'dialing',
            'last_attempted_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * Mark this entry as completed with an optional disposition.
     */
    public function markCompleted(?string $disposition = null): void
    {
        $this->update([
            'status' => 'completed',
            'last_disposition' => $disposition,
        ]);
    }

    /**
     * Mark this entry as skipped.
     */
    public function markSkipped(): void
    {
        $this->update([
            'status' => 'skipped',
        ]);
    }

    /**
     * Increment the number of dial attempts.
     */
    public function incrementAttempts(): void
    {
        $this->update([
            'attempts' => $this->attempts + 1,
            'last_attempted_at' => now(),
        ]);
    }

    /**
     * Re-queue a contact that was dialing (e.g., parallel line lost to first pickup).
     */
    public function reQueue(): void
    {
        $this->update([
            'status' => 'queued',
        ]);
    }

    /**
     * Schedule a callback by setting status to callback_scheduled.
     */
    public function markCallbackScheduled(): void
    {
        $this->update([
            'status' => 'callback_scheduled',
        ]);
    }

    /**
     * Flag this queue entry as priority with a reason.
     */
    public function markPriority(string $reason): void
    {
        $this->update(['is_priority' => true, 'priority_reason' => $reason]);
    }

    /**
     * Atomically claim this entry for an agent (returns false if already claimed).
     */
    public function claim(int $agentId): bool
    {
        return static::where('id', $this->id)
            ->whereNull('claimed_by_agent_id')
            ->update(['claimed_by_agent_id' => $agentId]) > 0;
    }

    /**
     * Release the claim on this entry.
     */
    public function unclaim(): void
    {
        $this->update(['claimed_by_agent_id' => null]);
    }

    public function recordCallAttempt(Campaign $campaign): void
    {
        $this->increment('calls_today');
        $this->increment('total_attempts');
        $this->increment('attempts');
        $this->update([
            'last_called_at' => now(),
            'last_attempted_at' => now(),
            'next_callable_at' => now()->addMinutes($campaign->min_minutes_between),
        ]);
    }

    public function isExhausted(Campaign $campaign): bool
    {
        if ($this->total_attempts >= $campaign->max_total_attempts) {
            return true;
        }
        if ($this->entered_at && $this->entered_at->diffInDays(now()) >= $campaign->max_days_in_campaign) {
            return true;
        }

        return false;
    }

    public function exhaust(): void
    {
        $this->update([
            'status' => 'exhausted',
            'exhausted_at' => now(),
        ]);
    }

    public function isCallableNow(Campaign $campaign): bool
    {
        if ($this->calls_today >= $campaign->max_calls_per_day) {
            return false;
        }
        if ($this->next_callable_at && $this->next_callable_at->isFuture()) {
            return false;
        }

        return true;
    }
}
