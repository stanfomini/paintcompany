<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Call extends Model
{
    use HasFactory;

    protected $table = 'prospector_calls';

    protected $fillable = [
        'ulid',
        'company_id',
        'dialer_session_id',
        'agent_id',
        'contact_id',
        'campaign_id',
        'batch_id',
        'direction',
        'from_number',
        'from_phone_number_id',
        'to_number',
        'twilio_call_sid',
        'twilio_conference_sid',
        'status',
        'disposition',
        'disposition_notes',
        'amd_result',
        'duration_seconds',
        'talk_seconds',
        'recording_url',
        'recording_path',
        'callback_at',
        'started_at',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'talk_seconds' => 'integer',
            'callback_at' => 'datetime',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Call $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function dialerSession(): BelongsTo
    {
        return $this->belongsTo(DialerSession::class, 'dialer_session_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function fromPhoneNumber(): BelongsTo
    {
        return $this->belongsTo(PhoneNumber::class, 'from_phone_number_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeForAgent(Builder $query, int $agentId): void
    {
        $query->where('agent_id', $agentId);
    }

    public function scopeForContact(Builder $query, int $contactId): void
    {
        $query->where('contact_id', $contactId);
    }

    public function scopeOutbound(Builder $query): void
    {
        $query->where('direction', 'outbound');
    }

    public function scopeInbound(Builder $query): void
    {
        $query->where('direction', 'inbound');
    }

    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', 'completed');
    }

    public function scopeToday(Builder $query): void
    {
        $query->whereDate('started_at', today());
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if the call has been completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Mark the call as answered.
     */
    public function markAnswered(): void
    {
        $this->update([
            'status' => 'in_progress',
            'answered_at' => now(),
        ]);
    }

    /**
     * Mark the call as completed with optional disposition.
     */
    public function markCompleted(?string $disposition = null, ?string $notes = null): void
    {
        $endedAt = now();
        $durationSeconds = $this->started_at ? $this->started_at->diffInSeconds($endedAt) : null;
        $talkSeconds = $this->answered_at ? $this->answered_at->diffInSeconds($endedAt) : null;

        $this->update([
            'status' => 'completed',
            'disposition' => $disposition,
            'disposition_notes' => $notes,
            'duration_seconds' => $durationSeconds,
            'talk_seconds' => $talkSeconds,
            'ended_at' => $endedAt,
        ]);
    }

    /**
     * Mark the call as failed with an optional status override.
     */
    public function markFailed(string $status = 'failed'): void
    {
        $this->update([
            'status' => $status,
            'ended_at' => now(),
        ]);
    }

    /**
     * Mark the call as canceled (used when another parallel line was picked up first).
     */
    public function markCanceled(): void
    {
        $this->update([
            'status' => 'canceled',
            'ended_at' => now(),
        ]);
    }
}
