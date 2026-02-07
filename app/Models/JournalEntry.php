<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_POSTED => 'Posted',
        self::STATUS_VOID => 'Void',
    ];

    public const REF_INVOICE = 'invoice';

    public const REF_PAYMENT = 'payment';

    public const REF_OVERHEAD = 'overhead';

    public const REF_PAYROLL = 'payroll';

    public const REF_MANUAL = 'manual';

    protected $fillable = [
        'company_setting_id', 'entry_number', 'transaction_date', 'recognition_date',
        'description', 'reference_type', 'reference_id', 'status', 'created_by',
        'posted_at', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'recognition_date' => 'date',
            'posted_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isBalanced(): bool
    {
        $lines = $this->lines;
        $totalDebits = $lines->sum('debit_amount');
        $totalCredits = $lines->sum('credit_amount');

        return round($totalDebits, 2) === round($totalCredits, 2);
    }

    public function totalDebits(): float
    {
        return (float) $this->lines->sum('debit_amount');
    }

    public function totalCredits(): float
    {
        return (float) $this->lines->sum('credit_amount');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function scopeByDateRange(Builder $query, string $mode, $from, $to): Builder
    {
        $column = $mode === 'accrual' ? 'recognition_date' : 'transaction_date';

        return $query->whereNotNull($column)->whereBetween($column, [$from, $to]);
    }
}
