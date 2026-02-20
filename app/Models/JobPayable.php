<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobPayable extends Model
{
    use HasFactory;

    public const PAYEE_TYPE_CREW = 'crew';

    public const PAYEE_TYPE_VENDOR = 'vendor';

    public const PAYEE_TYPE_EMPLOYEE = 'employee';

    public const PAYEE_TYPE_CONTRACTOR = 'contractor';

    public const PAYEE_TYPES = [
        self::PAYEE_TYPE_CREW => 'Crew',
        self::PAYEE_TYPE_VENDOR => 'Vendor',
        self::PAYEE_TYPE_EMPLOYEE => 'Employee',
        self::PAYEE_TYPE_CONTRACTOR => 'Contractor',
    ];

    public const CATEGORY_LABOR = 'labor';

    public const CATEGORY_MATERIALS = 'materials';

    public const CATEGORY_SUNDRIES = 'sundries';

    public const CATEGORY_TAX = 'tax';

    public const CATEGORY_CLEANUP = 'cleanup';

    public const CATEGORY_ADDON = 'addon';

    public const CATEGORY_COMMISSION = 'commission';

    public const CATEGORY_FLAT_RATE = 'flat_rate';

    public const CATEGORY_BONUS = 'bonus';

    public const CATEGORY_SUBCONTRACTOR = 'subcontractor';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_LABOR => 'Labor',
        self::CATEGORY_MATERIALS => 'Materials',
        self::CATEGORY_SUNDRIES => 'Sundries',
        self::CATEGORY_TAX => 'Tax',
        self::CATEGORY_CLEANUP => 'Cleanup',
        self::CATEGORY_ADDON => 'Add-on',
        self::CATEGORY_COMMISSION => 'Commission',
        self::CATEGORY_FLAT_RATE => 'Flat Rate',
        self::CATEGORY_BONUS => 'Bonus',
        self::CATEGORY_SUBCONTRACTOR => 'Subcontractor',
        self::CATEGORY_OTHER => 'Other',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_PAID => 'Paid',
        self::STATUS_VOID => 'Void',
    ];

    public const PAYMENT_METHOD_CHECK = 'check';

    public const PAYMENT_METHOD_ACH = 'ach';

    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHOD_STRIPE = 'stripe';

    public const PAYMENT_METHOD_OTHER = 'other';

    public const PAYMENT_METHODS = [
        self::PAYMENT_METHOD_CHECK => 'Check',
        self::PAYMENT_METHOD_ACH => 'ACH',
        self::PAYMENT_METHOD_CASH => 'Cash',
        self::PAYMENT_METHOD_STRIPE => 'Stripe',
        self::PAYMENT_METHOD_OTHER => 'Other',
    ];

    protected $fillable = [
        'job_id',
        'job_cost_card_id',
        'payee_type',
        'payee_user_id',
        'vendor_id',
        'payee_name',
        'service_category_id',
        'category',
        'description',
        'amount',
        'status',
        'due_date',
        'paid_at',
        'paid_via',
        'reference_number',
        'debit_account_id',
        'credit_account_id',
        'paid_from_account_id',
        'journal_entry_id',
        'approved_by',
        'created_by',
        'notes',
        'vendor_invoice_number',
        'attachment_path',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function jobCostCard(): BelongsTo
    {
        return $this->belongsTo(JobCostCard::class);
    }

    public function payeeUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_user_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'credit_account_id');
    }

    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ───

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PAID);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('payee_user_id', $userId);
    }

    public function scopeForJob(Builder $query, int $jobId): Builder
    {
        return $query->where('job_id', $jobId);
    }

    public function scopeForVendor(Builder $query, int $vendorId): Builder
    {
        return $query->where('vendor_id', $vendorId);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeStandalone(Builder $query): Builder
    {
        return $query->whereNull('job_id');
    }

    // ─── Status helpers ───

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    /**
     * Approve this payable.
     */
    public function approve(User $approver): self
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approver->id,
        ]);

        return $this;
    }

    /**
     * Mark this payable as paid.
     *
     * @param  array{paid_via?: string, reference_number?: string, paid_at?: string}  $data
     */
    public function markPaid(array $data = []): self
    {
        $this->update([
            'status' => self::STATUS_PAID,
            'paid_at' => $data['paid_at'] ?? now(),
            'paid_via' => $data['paid_via'] ?? null,
            'reference_number' => $data['reference_number'] ?? null,
        ]);

        return $this;
    }

    /**
     * Void this payable.
     */
    public function void(): self
    {
        $this->update([
            'status' => self::STATUS_VOID,
        ]);

        return $this;
    }

    public function hasAttachment(): bool
    {
        return ! empty($this->attachment_path);
    }
}
