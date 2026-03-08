<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessengerResponder extends Model
{
    /** @use HasFactory<\Database\Factories\MessengerResponderFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'role',
        'is_active',
        'sort_order',
        'last_assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_assigned_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isEstimator(): bool
    {
        return in_array($this->role, ['estimator', 'both']);
    }

    public function isBooker(): bool
    {
        return in_array($this->role, ['appointment_booker', 'both']);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeEstimators(Builder $query): Builder
    {
        return $query->whereIn('role', ['estimator', 'both']);
    }

    public function scopeBookers(Builder $query): Builder
    {
        return $query->whereIn('role', ['appointment_booker', 'both']);
    }
}
