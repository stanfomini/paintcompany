<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TerritoryOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'territory_id', 'work_date', 'status', 'offered_at', 'expires_at',
        'claimed_at', 'claimed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'territory_id');
    }

    public function claimedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function eligibleUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'territory_offer_users')
            ->withPivot(['notified_at', 'responded_at', 'response', 'queue_position'])
            ->withTimestamps();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isClaimable(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }
}
