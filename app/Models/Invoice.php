<?php

namespace App\Models;

use App\Models\Concerns\InvalidatesDashboardCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Invoice extends Model
{
    use HasFactory, InvalidatesDashboardCache;

    public const TYPE_DEPOSIT = 'deposit';

    public const TYPE_FINAL = 'final';

    public const TYPE_CHANGE_ORDER = 'change_order';

    public const TYPE_CUSTOM = 'custom';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PARTIALLY_PAID = 'partially_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUS_OVERDUE = 'overdue';

    public const TYPES = [
        'deposit' => 'Deposit',
        'final' => 'Final',
        'change_order' => 'Change Order',
        'custom' => 'Custom',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'partially_paid' => 'Partially Paid',
        'paid' => 'Paid',
        'void' => 'Void',
        'overdue' => 'Overdue',
    ];

    protected $fillable = [
        'company_setting_id',
        'estimate_id',
        'job_id',
        'customer_id',
        'invoice_number',
        'type',
        'status',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'amount_paid',
        'balance_due',
        'due_date',
        'sent_at',
        'paid_at',
        'stripe_checkout_session_id',
        'stripe_payment_intent_id',
        'public_token',
        'public_token_expires_at',
        'notes',
        'terms',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'public_token_expires_at' => 'datetime',
        ];
    }

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function generatePublicToken(int $expiryDays = 30): string
    {
        $this->public_token = Str::uuid()->toString();
        $this->public_token_expires_at = now()->addDays($expiryDays);
        $this->save();

        return $this->public_token;
    }

    public function getPublicUrl(): ?string
    {
        if (! $this->public_token) {
            return null;
        }

        return route('invoice.pay', ['token' => $this->public_token]);
    }

    public function isPublicTokenValid(): bool
    {
        return $this->public_token
            && $this->public_token_expires_at
            && $this->public_token_expires_at->isFuture();
    }

    public function revokePublicToken(): void
    {
        $this->public_token = null;
        $this->public_token_expires_at = null;
        $this->save();
    }

    public function recalculateAmounts(): void
    {
        $this->amount_paid = $this->payments()
            ->where('status', 'completed')
            ->sum('amount');

        $this->balance_due = (float) $this->total - (float) $this->amount_paid;

        if ($this->balance_due <= 0 && (float) $this->total > 0) {
            $this->status = self::STATUS_PAID;
            $this->paid_at = now();
        } elseif ((float) $this->amount_paid > 0) {
            $this->status = self::STATUS_PARTIALLY_PAID;
        }

        $this->save();

        // Advance referral status if deposit is paid
        if ($this->status === self::STATUS_PAID && $this->type === self::TYPE_DEPOSIT) {
            try {
                app(\App\Services\ReferralService::class)->onDepositPaid($this);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Sync payment summary to the job's cost card
        if ($this->job_id) {
            try {
                $job = $this->job;
                if ($job) {
                    app(\App\Services\JobCostCardService::class)->syncPaymentSummary($job);
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function canAcceptPayments(): bool
    {
        return in_array($this->status, [
            self::STATUS_SENT,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_OVERDUE,
        ]);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_SENT,
            self::STATUS_PARTIALLY_PAID,
            self::STATUS_OVERDUE,
        ]);
    }

    public function scopeByPublicToken(Builder $query, string $token): Builder
    {
        return $query->where('public_token', $token)
            ->where('public_token_expires_at', '>', now());
    }
}
