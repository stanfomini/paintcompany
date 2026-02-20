<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Vendor extends Model
{
    use HasFactory;

    public const CATEGORY_SUPPLIER = 'supplier';

    public const CATEGORY_SUBCONTRACTOR = 'subcontractor';

    public const CATEGORY_SERVICE = 'service';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_SUPPLIER => 'Supplier',
        self::CATEGORY_SUBCONTRACTOR => 'Subcontractor',
        self::CATEGORY_SERVICE => 'Service Provider',
        self::CATEGORY_OTHER => 'Other',
    ];

    protected $fillable = [
        'company_setting_id', 'name', 'contact_name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'zip',
        'category', 'budget_category_id', 'default_expense_account_id',
        'public_token', 'public_token_expires_at', 'onboarding_completed_at',
        'payment_terms_days', 'is_active', 'notes',
        'w9_on_file', 'hold_harmless', 'certificate_of_insurance',
        'insurance_expiration', 'additional_insured', 'workers_comp',
        'workers_comp_expiration', 'rejection_form',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
            'w9_on_file' => 'boolean',
            'hold_harmless' => 'boolean',
            'certificate_of_insurance' => 'boolean',
            'insurance_expiration' => 'date',
            'additional_insured' => 'boolean',
            'workers_comp' => 'boolean',
            'workers_comp_expiration' => 'date',
            'rejection_form' => 'boolean',
            'public_token_expires_at' => 'datetime',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_expense_account_id');
    }

    public function payables(): HasMany
    {
        return $this->hasMany(JobPayable::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocument::class);
    }

    // ─── Scopes ───

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        $threshold = now()->addDays($days);

        return $query->where(function (Builder $q) use ($threshold) {
            $q->where(function (Builder $q2) use ($threshold) {
                $q2->where('certificate_of_insurance', true)
                    ->whereNotNull('insurance_expiration')
                    ->where('insurance_expiration', '<=', $threshold);
            })->orWhere(function (Builder $q2) use ($threshold) {
                $q2->where('workers_comp', true)
                    ->whereNotNull('workers_comp_expiration')
                    ->where('workers_comp_expiration', '<=', $threshold);
            });
        });
    }

    // ─── Accessors ───

    protected function fullAddress(): Attribute
    {
        return Attribute::get(function () {
            $parts = array_filter([
                $this->address_line_1,
                $this->address_line_2,
            ]);

            $cityStateZip = array_filter([
                $this->city,
                $this->state ? ($this->city ? ', ' : '').$this->state : null,
                $this->zip,
            ]);

            if ($parts && $cityStateZip) {
                return implode(', ', $parts).', '.implode(' ', $cityStateZip);
            }

            return implode(', ', $parts).implode(' ', $cityStateZip);
        });
    }

    // ─── Helpers ───

    /**
     * Get total amount paid to this vendor in a given year.
     */
    public function totalPaidInYear(int $year): float
    {
        return (float) $this->payables()
            ->where('status', JobPayable::STATUS_PAID)
            ->whereYear('paid_at', $year)
            ->sum('amount');
    }

    /**
     * Check if this vendor requires a 1099 (paid >= $600 in year).
     */
    public function is1099Required(int $year): bool
    {
        return $this->totalPaidInYear($year) >= 600;
    }

    /**
     * Get compliance status as an array of checks.
     *
     * @return array<string, array{status: string, label: string, detail: string|null}>
     */
    public function complianceStatus(): array
    {
        $checks = [];

        $checks['w9'] = [
            'status' => $this->w9_on_file ? 'ok' : 'missing',
            'label' => 'W-9',
            'detail' => $this->w9_on_file ? 'On file' : 'Not received',
        ];

        $checks['insurance'] = [
            'status' => $this->getInsuranceStatus(),
            'label' => 'Insurance',
            'detail' => $this->getInsuranceDetail(),
        ];

        $checks['workers_comp'] = [
            'status' => $this->getWorkersCompStatus(),
            'label' => 'Workers Comp',
            'detail' => $this->getWorkersCompDetail(),
        ];

        $checks['hold_harmless'] = [
            'status' => $this->hold_harmless ? 'ok' : 'missing',
            'label' => 'Hold Harmless',
            'detail' => $this->hold_harmless ? 'Signed' : 'Not signed',
        ];

        return $checks;
    }

    public function hasExpiredInsurance(): bool
    {
        return $this->certificate_of_insurance
            && $this->insurance_expiration
            && $this->insurance_expiration->isPast();
    }

    public function hasExpiredWorkersComp(): bool
    {
        return $this->workers_comp
            && $this->workers_comp_expiration
            && $this->workers_comp_expiration->isPast();
    }

    public function hasPayables(): bool
    {
        return $this->payables()->exists();
    }

    protected function getInsuranceStatus(): string
    {
        if (! $this->certificate_of_insurance) {
            return 'missing';
        }

        if ($this->hasExpiredInsurance()) {
            return 'expired';
        }

        if ($this->insurance_expiration && $this->insurance_expiration->isBefore(now()->addDays(30))) {
            return 'warning';
        }

        return 'ok';
    }

    protected function getInsuranceDetail(): string
    {
        if (! $this->certificate_of_insurance) {
            return 'Not on file';
        }

        if (! $this->insurance_expiration) {
            return 'On file (no expiration)';
        }

        if ($this->hasExpiredInsurance()) {
            return 'Expired '.$this->insurance_expiration->format('M j, Y');
        }

        return 'Expires '.$this->insurance_expiration->format('M j, Y');
    }

    protected function getWorkersCompStatus(): string
    {
        if ($this->rejection_form) {
            return 'ok';
        }

        if (! $this->workers_comp) {
            return 'missing';
        }

        if ($this->hasExpiredWorkersComp()) {
            return 'expired';
        }

        if ($this->workers_comp_expiration && $this->workers_comp_expiration->isBefore(now()->addDays(30))) {
            return 'warning';
        }

        return 'ok';
    }

    protected function getWorkersCompDetail(): string
    {
        if ($this->rejection_form) {
            return 'Rejection form on file';
        }

        if (! $this->workers_comp) {
            return 'Not on file';
        }

        if (! $this->workers_comp_expiration) {
            return 'On file (no expiration)';
        }

        if ($this->hasExpiredWorkersComp()) {
            return 'Expired '.$this->workers_comp_expiration->format('M j, Y');
        }

        return 'Expires '.$this->workers_comp_expiration->format('M j, Y');
    }

    // ─── Token Methods ───

    public function generatePublicToken(int $expiryDays = 30): string
    {
        $this->update([
            'public_token' => Str::random(64),
            'public_token_expires_at' => now()->addDays($expiryDays),
        ]);

        return $this->public_token;
    }

    public function isPublicTokenValid(): bool
    {
        return $this->public_token
            && $this->public_token_expires_at
            && $this->public_token_expires_at->isFuture();
    }

    public function getOnboardingUrl(): ?string
    {
        if (! $this->public_token) {
            return null;
        }

        return route('vendor.onboarding', ['token' => $this->public_token]);
    }

    public function markOnboardingComplete(): void
    {
        $this->update(['onboarding_completed_at' => now()]);
    }

    public function hasCompletedOnboarding(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function scopeByPublicToken(Builder $query, string $token): Builder
    {
        return $query->where('public_token', $token);
    }
}
