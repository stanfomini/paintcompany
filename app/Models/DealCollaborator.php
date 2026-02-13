<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealCollaborator extends Model
{
    use HasFactory;

    protected $fillable = [
        'deal_id',
        'customer_id',
        'invited_by',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if this collaboration is currently active (not revoked and not expired).
     */
    public function isActive(): bool
    {
        return is_null($this->revoked_at) && $this->expires_at->isFuture();
    }

    /**
     * Get the number of days remaining until expiration.
     */
    public function daysRemaining(): int
    {
        if ($this->revoked_at || $this->expires_at->isPast()) {
            return 0;
        }

        return (int) now()->diffInDays($this->expires_at, absolute: false);
    }

    /**
     * Scope to active collaborations (not revoked and not expired).
     */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * Scope to collaborations for a specific customer.
     */
    public function scopeForCustomer($query, Customer $customer)
    {
        return $query->where('customer_id', $customer->id);
    }
}
