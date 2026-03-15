<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsConversation extends Model
{
    use HasFactory;

    public const STATE_INITIATED = 'initiated';

    public const STATE_NUDGE_PHOTO = 'nudge_photo';

    public const STATE_PHOTO_RECEIVED = 'photo_received';

    public const STATE_VISUALIZATION_READY = 'visualization_ready';

    public const STATE_ROOM_LABELED = 'room_labeled';

    public const STATE_SCOPE_COLLECTED = 'scope_collected';

    public const STATE_TIMELINE_COLLECTED = 'timeline_collected';

    public const STATE_OPEN_CHAT = 'open_chat';

    public const STATE_HUMAN_TAKEOVER = 'human_takeover';

    public const STATE_DORMANT = 'dormant';

    protected $fillable = [
        'project_preview_id',
        'flow_id',
        'phone',
        'current_state',
        'sms_session_id',
        'chat_thread_id',
        'answers',
        'message_count',
        'photo_count',
        'last_message_at',
        'expires_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'last_message_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'message_count' => 'integer',
            'photo_count' => 'integer',
        ];
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(ProjectPreview::class, 'project_preview_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(SmsBotFlow::class, 'flow_id');
    }

    public function smsSession(): BelongsTo
    {
        return $this->belongsTo(SmsSession::class);
    }

    public function chatThread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class);
    }

    /**
     * Scope to active conversations (not completed, not expired, not dormant).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('completed_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where('current_state', '!=', self::STATE_DORMANT);
    }

    /**
     * Scope to conversations that have timed out.
     *
     * @param  Builder<self>  $query
     */
    public function scopeTimedOut(Builder $query): Builder
    {
        return $query->whereNull('completed_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Find an active conversation for the given phone number.
     */
    public static function findActiveByPhone(string $phone): ?self
    {
        return static::query()
            ->active()
            ->where('phone', $phone)
            ->latest('last_message_at')
            ->first();
    }

    public function isActive(): bool
    {
        if ($this->completed_at) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return $this->current_state !== self::STATE_DORMANT;
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function isHumanTakeover(): bool
    {
        return $this->current_state === self::STATE_HUMAN_TAKEOVER;
    }

    /**
     * Transition the conversation to a new state.
     */
    public function transitionTo(string $state): void
    {
        $this->update([
            'current_state' => $state,
            'last_message_at' => now(),
        ]);
    }
}
