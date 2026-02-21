<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannerDelegation extends Model
{
    /** @use HasFactory<\Database\Factories\PlannerDelegationFactory> */
    use HasFactory;

    public const ACCESS_LEVELS = [
        'view' => 'View Only',
        'act' => 'Act (Complete, Snooze, Prioritize)',
        'full' => 'Full Control',
    ];

    public const ACCESS_HIERARCHY = ['view' => 0, 'act' => 1, 'full' => 2];

    protected $fillable = [
        'grantor_id',
        'target_user_id',
        'delegate_id',
        'access_level',
        'starts_at',
        'expires_at',
        'is_permanent',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_permanent' => 'boolean',
        ];
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grantor_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->where('is_permanent', true)
                ->orWhere('expires_at', '>', now());
        })->where(function ($q) {
            $q->whereNull('starts_at')
                ->orWhere('starts_at', '<=', now());
        });
    }

    public function scopeForDelegate($query, User $user)
    {
        return $query->where('delegate_id', $user->id);
    }

    public function scopeForTarget($query, User $user)
    {
        return $query->where('target_user_id', $user->id);
    }

    public function isActive(): bool
    {
        if ($this->starts_at && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->is_permanent) {
            return true;
        }

        return $this->expires_at && $this->expires_at->isFuture();
    }

    public function meetsAccessLevel(string $requiredLevel): bool
    {
        $hierarchy = self::ACCESS_HIERARCHY;

        return ($hierarchy[$this->access_level] ?? 0) >= ($hierarchy[$requiredLevel] ?? 0);
    }
}
