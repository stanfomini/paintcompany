<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PtoRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'type',
        'start_date',
        'end_date',
        'hours',
        'status',
        'approved_by',
        'approved_at',
        'denial_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'hours' => 'decimal:1',
            'approved_at' => 'datetime',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Scope: pending requests.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: approved requests.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope: requests within a date range.
     */
    public function scopeForDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);
    }

    /**
     * Check if this request is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Get the total number of days (inclusive).
     */
    public function getTotalDays(): int
    {
        return $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Approve this PTO request.
     */
    public function approve(User $by): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $by->id,
            'approved_at' => now(),
        ]);
    }

    /**
     * Deny this PTO request.
     */
    public function deny(User $by, string $reason): void
    {
        $this->update([
            'status' => 'denied',
            'approved_by' => $by->id,
            'approved_at' => now(),
            'denial_reason' => $reason,
        ]);
    }
}
