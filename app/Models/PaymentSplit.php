<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentSplit extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentSplitFactory> */
    use HasFactory;

    public const TYPE_STRIPE_CONNECT = 'stripe_connect';

    public const TYPE_INCREASE_ACH = 'increase_ach';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'payment_id',
        'destination_name',
        'destination_type',
        'destination_account_id',
        'amount',
        'percentage',
        'status',
        'transfer_id',
        'error_message',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'percentage' => 'decimal:2',
            'executed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markCompleted(string $transferId): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'transfer_id' => $transferId,
            'executed_at' => now(),
            'error_message' => null,
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }
}
