<?php

namespace App\Models;

use App\Traits\HasCommissionRules;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeProfile extends Model
{
    use HasCommissionRules, HasFactory;

    public const PAY_TYPES = [
        'hourly' => 'Hourly',
        'salary' => 'Salary',
        'commission' => 'Commission Only',
        'hourly_plus_commission' => 'Hourly + Commission',
        'salary_plus_commission' => 'Salary + Commission',
    ];

    protected $fillable = [
        'user_id',
        'employee_number',
        'hire_date',
        'termination_date',
        'employment_status',
        'pay_rate',
        'pay_type',
        'department',
        'job_title',
        'date_of_birth',
        'ssn',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'zip_code',
        'phone',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'bank_name',
        'bank_routing_number',
        'bank_account_number',
        'bank_account_type',
        'w4_filing_status',
        'w4_allowances',
        'w4_additional_withholding',
        'commission_rules',
        'commission_tax_method',
        'lead_safe_cert_number',
        'lead_safe_cert_expiration',
        'lead_safe_cert_document',
        'onboarding_completed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'termination_date' => 'date',
            'pay_rate' => 'decimal:2',
            'date_of_birth' => 'date',
            'ssn' => 'encrypted',
            'bank_routing_number' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'w4_allowances' => 'integer',
            'w4_additional_withholding' => 'decimal:2',
            'commission_rules' => 'array',
            'lead_safe_cert_expiration' => 'date',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * Generate the next employee number (EMP-001 format).
     */
    public static function generateEmployeeNumber(): string
    {
        $maxNumber = static::query()
            ->where('employee_number', 'like', 'EMP-%')
            ->get(['employee_number'])
            ->map(fn (self $model) => (int) substr($model->employee_number, 4))
            ->max() ?? 0;

        return 'EMP-'.str_pad($maxNumber + 1, 3, '0', STR_PAD_LEFT);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function onboardingProgress(): HasMany
    {
        return $this->hasMany(EmployeeOnboardingProgress::class);
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(EmployeeEquipment::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function ptoRequests(): HasMany
    {
        return $this->hasMany(PtoRequest::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(EmployeeAvailability::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(EmployeeDeduction::class);
    }

    public function payStubs(): HasMany
    {
        return $this->hasMany(PayStub::class);
    }

    public function commissionEntries(): HasMany
    {
        return $this->hasMany(CommissionEntry::class);
    }

    public function commissionTiers(): HasMany
    {
        return $this->hasMany(CommissionTier::class);
    }

    /**
     * Get commission rules for a specific source (lead, estimate, job, total).
     *
     * @return array<int, array{source: string, type: string, rate?: float, amount?: float}>
     */
    public function getCommissionRulesForSource(string $source): array
    {
        return collect($this->commission_rules ?? [])
            ->filter(fn (array $rule) => ($rule['source'] ?? '') === $source && ($rule['active'] ?? true))
            ->values()
            ->all();
    }

    /**
     * Get commission rules for a source that have auto_trigger enabled.
     * Used by lifecycle hooks (Deal assignment, Job completion) to only fire automatic commissions.
     *
     * @return array<int, array{source: string, type: string, rate?: float, amount?: float}>
     */
    public function getAutoCommissionRulesForSource(string $source): array
    {
        return collect($this->commission_rules ?? [])
            ->filter(fn (array $rule) => ($rule['source'] ?? '') === $source
                && ($rule['active'] ?? true)
                && ($rule['auto_trigger'] ?? true)
            )
            ->values()
            ->all();
    }

    /**
     * Check if this employee earns commissions.
     */
    public function hasCommissionRules(): bool
    {
        return ! empty($this->commission_rules);
    }

    public function isCommissionOnly(): bool
    {
        return $this->pay_type === 'commission';
    }

    public function hasHourlyBase(): bool
    {
        return in_array($this->pay_type, ['hourly', 'hourly_plus_commission']);
    }

    public function hasSalaryBase(): bool
    {
        return in_array($this->pay_type, ['salary', 'salary_plus_commission']);
    }

    public function hasCommissionComponent(): bool
    {
        return in_array($this->pay_type, ['commission', 'hourly_plus_commission', 'salary_plus_commission']);
    }

    public function getPayRateLabel(): string
    {
        if ($this->isCommissionOnly()) {
            return __('Commission');
        }

        if (! $this->pay_rate) {
            return '-';
        }

        $suffix = $this->hasSalaryBase() ? '/yr' : '/hr';
        $label = '$'.number_format($this->pay_rate, 2).$suffix;

        if ($this->hasCommissionComponent()) {
            $label .= ' + '.__('Commission');
        }

        return $label;
    }

    public function isActive(): bool
    {
        return $this->employment_status === 'active';
    }

    public function isOnLeave(): bool
    {
        return $this->employment_status === 'on_leave';
    }

    public function isTerminated(): bool
    {
        return $this->employment_status === 'terminated';
    }

    public function isOnboardingComplete(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    public function isLeadSafeCertExpired(): bool
    {
        if (! $this->lead_safe_cert_expiration) {
            return false;
        }

        return $this->lead_safe_cert_expiration->isPast();
    }

    public function isLeadSafeCertExpiringSoon(): bool
    {
        if (! $this->lead_safe_cert_expiration) {
            return false;
        }

        return $this->lead_safe_cert_expiration->isBetween(now(), now()->addDays(30));
    }

    /**
     * Get the employee's full address as a single string.
     */
    public function getFullAddress(): ?string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state ? ($this->state.' '.$this->zip_code) : $this->zip_code,
        ]);

        return count($parts) > 0 ? implode(', ', $parts) : null;
    }

    /**
     * Get the annual salary (for salary employees) or estimated annual (for hourly).
     * Commission-only employees have no base pay to estimate.
     */
    public function getEstimatedAnnualPay(): ?float
    {
        if ($this->isCommissionOnly()) {
            return null;
        }

        if (! $this->pay_rate) {
            return null;
        }

        if ($this->hasSalaryBase()) {
            return (float) $this->pay_rate;
        }

        // Hourly: rate * 40 hours * 52 weeks
        return round((float) $this->pay_rate * 40 * 52, 2);
    }

    /**
     * Terminate the employee.
     */
    public function terminate(?string $notes = null): void
    {
        $this->update([
            'employment_status' => 'terminated',
            'termination_date' => now()->toDateString(),
            'notes' => $notes ? ($this->notes ? $this->notes."\n\n".$notes : $notes) : $this->notes,
        ]);

        $this->user->is_active = false;
        $this->user->save();
    }
}
