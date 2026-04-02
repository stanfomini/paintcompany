<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DialerSession extends Model
{
    use HasFactory;

    protected $table = 'prospector_dialer_sessions';

    protected $fillable = [
        'ulid',
        'agent_id',
        'campaign_id',
        'mode',
        'parallel_lines',
        'current_batch_id',
        'status',
        'calls_attempted',
        'calls_connected',
        'total_talk_seconds',
        'started_at',
        'ended_at',
        'conference_name',
    ];

    protected function casts(): array
    {
        return [
            'parallel_lines' => 'integer',
            'calls_attempted' => 'integer',
            'calls_connected' => 'integer',
            'total_talk_seconds' => 'integer',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DialerSession $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class, 'dialer_session_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    public function scopeForAgent(Builder $query, int $agentId): void
    {
        $query->where('agent_id', $agentId);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if the session is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if this is a parallel dialing session.
     */
    public function isParallel(): bool
    {
        return $this->mode === 'parallel';
    }

    /**
     * Increment the calls attempted counter.
     */
    public function incrementAttempted(): void
    {
        $this->increment('calls_attempted');
    }

    /**
     * Increment the calls connected counter.
     */
    public function incrementConnected(): void
    {
        $this->increment('calls_connected');
    }

    /**
     * Add talk time seconds to the session total.
     */
    public function addTalkTime(int $seconds): void
    {
        $this->increment('total_talk_seconds', $seconds);
    }

    /**
     * Mark the session as completed.
     */
    public function complete(): void
    {
        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
    }

    /**
     * Mark the session as abandoned.
     */
    public function abandon(): void
    {
        $this->update([
            'status' => 'abandoned',
            'ended_at' => now(),
        ]);
    }
}
