<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CallLog extends Model
{
    /** @use HasFactory<\Database\Factories\CallLogFactory> */
    use HasFactory;

    protected $fillable = [
        'ulid',
        'twilio_call_sid',
        'direction',
        'from_number',
        'to_number',
        'customer_id',
        'caller_user_id',
        'answered_by_user_id',
        'status',
        'duration_seconds',
        'recording_sid',
        'recording_url',
        'recording_duration_seconds',
        'voicemail_transcription',
        'metadata',
        'voicemail_for_user_id',
        'call_group_id',
        'follow_up_activity_id',
        'started_at',
        'answered_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'recording_duration_seconds' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CallLog $callLog) {
            if (empty($callLog->ulid)) {
                $callLog->ulid = Str::ulid()->toBase32();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function callerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_user_id');
    }

    public function answeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'answered_by_user_id');
    }

    public function voicemailForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voicemail_for_user_id');
    }

    public function callGroup(): BelongsTo
    {
        return $this->belongsTo(CallGroup::class);
    }

    public function followUpActivity(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'follow_up_activity_id');
    }

    public function userInteractions(): HasMany
    {
        return $this->hasMany(CallLogUser::class);
    }

    public function scopeInbound(Builder $query): void
    {
        $query->where('direction', 'inbound');
    }

    public function scopeOutbound(Builder $query): void
    {
        $query->where('direction', 'outbound');
    }

    public function scopeMissed(Builder $query): void
    {
        $query->whereIn('status', ['no-answer', 'voicemail']);
    }

    public function scopeForCustomer(Builder $query, int $customerId): void
    {
        $query->where('customer_id', $customerId);
    }

    public function scopeVoicemails(Builder $query): void
    {
        $query->where('status', 'voicemail');
    }

    public function scopeCompanyWide(Builder $query): void
    {
        $query->whereNull('voicemail_for_user_id');
    }

    public function scopeForUser(Builder $query, int $userId): void
    {
        $query->where('voicemail_for_user_id', $userId);
    }

    public function scopeUnlistenedBy(Builder $query, int $userId): void
    {
        $query->whereDoesntHave('userInteractions', function ($q) use ($userId) {
            $q->where('user_id', $userId)->whereNotNull('listened_at');
        });
    }

    public function scopeUnhandled(Builder $query): void
    {
        $query->whereDoesntHave('userInteractions', function ($q) {
            $q->whereNotNull('handled_at');
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(function (Builder $q) use ($user) {
            $q->where('voicemail_for_user_id', $user->id);

            if ($user->can('phone.view_company_voicemails') || $user->can('phone.receive_calls')) {
                $q->orWhereNull('voicemail_for_user_id');
            }

            if ($user->can('phone.view_all_voicemails')) {
                $q->orWhereNotNull('id');
            }
        });
    }

    public function isVoicemail(): bool
    {
        return $this->status === 'voicemail';
    }

    public function hasRecording(): bool
    {
        return ! empty($this->recording_url);
    }

    public function isCompanyWide(): bool
    {
        return $this->voicemail_for_user_id === null;
    }

    public function isForUser(): bool
    {
        return $this->voicemail_for_user_id !== null;
    }

    /**
     * Get the raw recording URL with .mp3 extension for Twilio URLs.
     * Used internally for downloading from Twilio (with auth).
     */
    public function getRawRecordingUrl(): ?string
    {
        if (empty($this->recording_url)) {
            return null;
        }

        $url = $this->recording_url;

        if (str_contains($url, 'api.twilio.com') && ! str_ends_with($url, '.mp3')) {
            $url = rtrim($url, '/').'.mp3';
        }

        return $url;
    }

    /**
     * Get the playable recording URL for the browser.
     * Returns our proxy route (handles Twilio auth and local storage).
     */
    public function getPlayableRecordingUrl(): ?string
    {
        if (empty($this->recording_url)) {
            return null;
        }

        try {
            return route('calls.recording', $this);
        } catch (\Throwable) {
            return $this->getRawRecordingUrl();
        }
    }

    public function isMissedCall(): bool
    {
        return $this->status === 'no-answer';
    }

    public function markListenedBy(User $user): void
    {
        CallLogUser::updateOrCreate(
            ['call_log_id' => $this->id, 'user_id' => $user->id],
            ['listened_at' => now()]
        );
    }

    public function markHandledBy(User $user, string $action): void
    {
        CallLogUser::updateOrCreate(
            ['call_log_id' => $this->id, 'user_id' => $user->id],
            ['handled_at' => now(), 'handled_action' => $action]
        );
    }

    public function isListenedBy(User $user): bool
    {
        return $this->userInteractions()
            ->where('user_id', $user->id)
            ->whereNotNull('listened_at')
            ->exists();
    }

    public function isHandled(): bool
    {
        return $this->userInteractions()
            ->whereNotNull('handled_at')
            ->exists();
    }

    /**
     * Format call duration as mm:ss or hh:mm:ss.
     */
    public function getFormattedDuration(): string
    {
        $seconds = $this->duration_seconds ?? 0;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
