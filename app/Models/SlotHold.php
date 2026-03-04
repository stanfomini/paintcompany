<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlotHold extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'estimator_id',
        'hold_date',
        'hold_start_time',
        'hold_end_time',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'hold_date' => 'date',
            'expires_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function estimator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'estimator_id');
    }

    // ─── Scopes ───

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    public function scopeForEstimator(Builder $query, int $estimatorId): Builder
    {
        return $query->where('estimator_id', $estimatorId);
    }

    public function scopeForDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('hold_date', $date);
    }

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    // ─── Methods ───

    public function isActive(): bool
    {
        return $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
