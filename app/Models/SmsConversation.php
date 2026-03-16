<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_preview_id',
        'flow_id',
        'phone',
        'current_state',
        'current_step_id',
        'status',
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

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(SmsBotStep::class, 'current_step_id');
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
     * Scope to active conversations (in_progress status, not expired).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'in_progress')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
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
        return $this->status === 'in_progress'
            && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function isHumanTakeover(): bool
    {
        return $this->status === 'human_takeover';
    }

    public function isAbandoned(): bool
    {
        return $this->status === 'abandoned';
    }
}
