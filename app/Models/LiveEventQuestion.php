<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveEventQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\LiveEventQuestionFactory> */
    use HasFactory;

    protected $fillable = [
        'live_event_id',
        'viewer_name',
        'user_id',
        'question',
        'status',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
        ];
    }

    /**
     * Get the live event this question belongs to.
     */
    public function liveEvent(): BelongsTo
    {
        return $this->belongsTo(LiveEvent::class);
    }

    /**
     * Get the user who asked this question.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter pending questions.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter answered questions.
     */
    public function scopeAnswered(Builder $query): Builder
    {
        return $query->where('status', 'answered');
    }

    /**
     * Scope to filter dismissed questions.
     */
    public function scopeDismissed(Builder $query): Builder
    {
        return $query->where('status', 'dismissed');
    }
}
