<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LiveEventGuest extends Model
{
    /** @use HasFactory<\Database\Factories\LiveEventGuestFactory> */
    use HasFactory;

    protected $fillable = [
        'live_event_id',
        'name',
        'email',
        'phone',
        'instructions',
        'token',
        'status',
        'livekit_identity',
        'slot_number',
        'connected_at',
        'went_live_at',
    ];

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'went_live_at' => 'datetime',
        ];
    }

    public function liveEvent(): BelongsTo
    {
        return $this->belongsTo(LiveEvent::class);
    }

    public function isInvited(): bool
    {
        return $this->status === 'invited';
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isLive(): bool
    {
        return $this->status === 'live';
    }

    public function isDisconnected(): bool
    {
        return $this->status === 'disconnected';
    }

    /**
     * Get the LiveKit identity string for this guest.
     */
    public function getLivekitIdentity(): string
    {
        return $this->livekit_identity ?? "guest-{$this->id}";
    }

    protected static function booted(): void
    {
        static::creating(function (self $guest) {
            if (empty($guest->token)) {
                $guest->token = Str::random(64);
            }
            if (empty($guest->livekit_identity)) {
                $guest->livekit_identity = 'guest-'.Str::random(8);
            }
            if (empty($guest->status)) {
                $guest->status = 'invited';
            }
        });
    }
}
