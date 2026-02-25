<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class RecordingInvite extends Model
{
    /** @use HasFactory<\Database\Factories\RecordingInviteFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'title',
        'instructions',
        'max_duration_seconds',
        'expires_at',
        'video_recording_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'max_duration_seconds' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (RecordingInvite $invite) {
            if (empty($invite->token)) {
                $invite->token = Str::random(64);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function videoRecording(): BelongsTo
    {
        return $this->belongsTo(VideoRecording::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && now()->greaterThan($this->expires_at);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getInviteUrl(): string
    {
        return URL::signedRoute('recording.invite', ['token' => $this->token]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
