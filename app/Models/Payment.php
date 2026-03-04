<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesDashboardCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasFactory, InvalidatesDashboardCache;

    public const METHOD_STRIPE = 'stripe';

    public const METHOD_CHECK = 'check';

    public const METHOD_CASH = 'cash';

    public const METHOD_ACH = 'ach';

    public const METHOD_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const METHODS = [
        'stripe' => 'Stripe',
        'check' => 'Check',
        'cash' => 'Cash',
        'ach' => 'ACH',
        'other' => 'Other',
    ];

    public const STATUSES = [
        'pending' => 'Pending',
        'completed' => 'Completed',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
    ];

    protected $fillable = [
        'invoice_id',
        'company_setting_id',
        'amount',
        'method',
        'status',
        'stripe_payment_intent_id',
        'reference_number',
        'notes',
        'received_at',
        'recorded_by',
        'split_status',
        'split_executed_at',
        'split_details',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'received_at' => 'datetime',
            'split_executed_at' => 'datetime',
            'split_details' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(PaymentSplit::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
