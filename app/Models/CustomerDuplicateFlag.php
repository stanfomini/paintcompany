<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDuplicateFlag extends Model
{
    /** @use HasFactory<\Database\Factories\CustomerDuplicateFlagFactory> */
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'duplicate_customer_id',
        'duplicate_user_id',
        'match_type',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Get the primary customer record.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the duplicate customer record.
     */
    public function duplicateCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'duplicate_customer_id');
    }

    /**
     * Get the duplicate user record (for staff/user matches).
     */
    public function duplicateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'duplicate_user_id');
    }

    /**
     * Get the user who resolved this flag.
     */
    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Scope to pending flags only.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Check if this flag is a staff/user match (not customer-to-customer).
     */
    public function isStaffMatch(): bool
    {
        return $this->duplicate_user_id !== null;
    }
}
