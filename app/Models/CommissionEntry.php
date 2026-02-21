<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommissionEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_profile_id',
        'commissionable_type',
        'commissionable_id',
        'source_type',
        'source_id',
        'source_label',
        'commission_type',
        'basis_amount',
        'rate',
        'amount',
        'status',
        'earned_date',
        'pay_period_id',
        'pay_stub_id',
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'basis_amount' => 'decimal:2',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'earned_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function employeeProfile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class);
    }

    public function commissionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function payPeriod(): BelongsTo
    {
        return $this->belongsTo(PayPeriod::class);
    }

    public function payStub(): BelongsTo
    {
        return $this->belongsTo(PayStub::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', 'paid');
    }

    public function scopeForEmployee(Builder $query, int $employeeProfileId): Builder
    {
        return $query->where('employee_profile_id', $employeeProfileId);
    }

    public function scopeForCommissionable(Builder $query, Model $model): Builder
    {
        return $query->where('commissionable_type', get_class($model))
            ->where('commissionable_id', $model->getKey());
    }

    public function scopeEarnedBetween(Builder $query, $start, $end): Builder
    {
        return $query->whereBetween('earned_date', [$start, $end]);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isReversed(): bool
    {
        return $this->status === 'reversed';
    }

    public function approve(User $approver): void
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function reverse(?string $reason = null): void
    {
        $notes = $this->notes;
        if ($reason) {
            $notes = $notes ? $notes."\nReversed: ".$reason : 'Reversed: '.$reason;
        }

        $this->update([
            'status' => 'reversed',
            'notes' => $notes,
        ]);
    }
}
