<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function isVoicemail(): bool
    {
        return $this->status === 'voicemail';
    }

    public function hasRecording(): bool
    {
        return ! empty($this->recording_url);
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
