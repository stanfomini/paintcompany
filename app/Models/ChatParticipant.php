<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'thread_id',
        'user_id',
        'last_read_at',
        'is_muted',
        'archived_at',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'is_muted' => 'boolean',
            'archived_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * Get the thread this participant belongs to.
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'thread_id');
    }

    /**
     * Get the user for this participant.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this participant has left the thread.
     */
    public function hasLeft(): bool
    {
        return $this->left_at !== null;
    }

    /**
     * Check if this participant is active in the thread.
     */
    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    /**
     * Mark messages as read up to the current time.
     */
    public function markAsRead(): bool
    {
        return $this->update(['last_read_at' => now()]);
    }

    /**
     * Toggle mute status.
     */
    public function toggleMute(): bool
    {
        return $this->update(['is_muted' => ! $this->is_muted]);
    }

    /**
     * Archive this thread for this participant.
     */
    public function archive(): bool
    {
        return $this->update(['archived_at' => now()]);
    }

    /**
     * Unarchive this thread for this participant.
     */
    public function unarchive(): bool
    {
        return $this->update(['archived_at' => null]);
    }

    /**
     * Check if this participant has archived the thread.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Scope to filter active participants only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('left_at');
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
