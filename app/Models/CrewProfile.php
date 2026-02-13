<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewProfile extends Model
{
    protected $fillable = [
        'user_id',
        'service_category_id',
        'company_name',
        'phone',
        'crew_size',
        'specialties',
        'hourly_rate',
        'bonus_percent',
        'bio',
        'insurance_company',
        'policy_number',
        'insurance_start_date',
        'insurance_expiration_date',
        'insurance_photo',
        'insurance_photo_type',
        'workers_comp_carrier',
        'workers_comp_policy_number',
        'workers_comp_start_date',
        'workers_comp_expiration_date',
        'workers_comp_document',
        'workers_comp_document_type',
        'w9_photo',
        'w9_photo_type',
        'w9_received_date',
        'license_number',
        'license_expiration_date',
        'notes',
        // Onboarding fields
        'had_onboarding_call',
        'onboarding_completed_at',
        'drivers_license_front',
        'drivers_license_back',
        'onboarding_call_confirmed_by',
        'onboarding_call_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'crew_size' => 'integer',
            'specialties' => 'array',
            'hourly_rate' => 'decimal:2',
            'bonus_percent' => 'decimal:2',
            'insurance_start_date' => 'date',
            'insurance_expiration_date' => 'date',
            'workers_comp_start_date' => 'date',
            'workers_comp_expiration_date' => 'date',
            'w9_received_date' => 'date',
            'license_expiration_date' => 'date',
            'had_onboarding_call' => 'boolean',
            'onboarding_completed_at' => 'datetime',
            'onboarding_call_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Check if insurance expires within 30 days.
     */
    public function isInsuranceExpiringSoon(): bool
    {
        if (! $this->insurance_expiration_date) {
            return false;
        }

        return $this->insurance_expiration_date->isBetween(now(), now()->addDays(30));
    }

    /**
     * Check if insurance has expired.
     */
    public function isInsuranceExpired(): bool
    {
        if (! $this->insurance_expiration_date) {
            return false;
        }

        return $this->insurance_expiration_date->isPast();
    }

    /**
     * Check if license expires within 30 days.
     */
    public function isLicenseExpiringSoon(): bool
    {
        if (! $this->license_expiration_date) {
            return false;
        }

        return $this->license_expiration_date->isBetween(now(), now()->addDays(30));
    }

    /**
     * Check if license has expired.
     */
    public function isLicenseExpired(): bool
    {
        if (! $this->license_expiration_date) {
            return false;
        }

        return $this->license_expiration_date->isPast();
    }

    /**
     * Check if workers' comp expires within 30 days.
     */
    public function isWorkersCompExpiringSoon(): bool
    {
        if (! $this->workers_comp_expiration_date) {
            return false;
        }

        return $this->workers_comp_expiration_date->isBetween(now(), now()->addDays(30));
    }

    /**
     * Check if workers' comp has expired.
     */
    public function isWorkersCompExpired(): bool
    {
        if (! $this->workers_comp_expiration_date) {
            return false;
        }

        return $this->workers_comp_expiration_date->isPast();
    }

    /**
     * Check if crew has any compliance warnings.
     */
    public function hasComplianceWarnings(): bool
    {
        return $this->isInsuranceExpiringSoon()
            || $this->isInsuranceExpired()
            || $this->isLicenseExpiringSoon()
            || $this->isLicenseExpired()
            || $this->isWorkersCompExpiringSoon()
            || $this->isWorkersCompExpired();
    }

    /**
     * Get the overall compliance status.
     */
    public function getComplianceStatus(): string
    {
        if ($this->isInsuranceExpired() || $this->isLicenseExpired() || $this->isWorkersCompExpired()) {
            return 'expired';
        }

        if ($this->isInsuranceExpiringSoon() || $this->isLicenseExpiringSoon() || $this->isWorkersCompExpiringSoon()) {
            return 'warning';
        }

        return 'compliant';
    }

    /**
     * Calculate the bonus amount for a given job price.
     */
    public function calculateBonusAmount(float $jobPrice): ?float
    {
        if (! $this->bonus_percent || $this->bonus_percent <= 0) {
            return null;
        }

        return round($jobPrice * ($this->bonus_percent / 100), 2);
    }

    /**
     * Check if onboarding is complete.
     */
    public function isOnboardingComplete(): bool
    {
        return $this->onboarding_completed_at !== null;
    }

    /**
     * Get the current onboarding step (1-5) based on what's been completed.
     */
    public function getCurrentOnboardingStep(): int
    {
        $user = $this->user;

        // Step 1: Pitch - always viewable, considered done once you advance
        // Step 2: Call
        if (! $this->had_onboarding_call) {
            return 2;
        }

        // Step 3: Contracts
        $signedContracts = $user->crewSignedContracts()->count();
        if ($signedContracts < 3) {
            return 3;
        }

        // Step 4: Documents
        $hasInsurance = ! empty($this->insurance_photo);
        $hasWorkersComp = ! empty($this->workers_comp_document);
        $hasW9 = $user->crewW9Form()->exists();
        $hasLicense = ! empty($this->drivers_license_front);

        if (! $hasInsurance || ! $hasWorkersComp || ! $hasW9 || ! $hasLicense) {
            return 4;
        }

        // Step 5: Complete
        return 5;
    }

    /**
     * Get onboarding progress as a percentage.
     */
    public function getOnboardingProgressPercent(): int
    {
        if ($this->isOnboardingComplete()) {
            return 100;
        }

        $step = $this->getCurrentOnboardingStep();

        return match ($step) {
            1 => 0,
            2 => 20,
            3 => 40,
            4 => 60,
            5 => 80,
            default => 0,
        };
    }

    /**
     * Mark the onboarding call as confirmed by a team member.
     */
    public function confirmOnboardingCall(User $confirmedBy): void
    {
        $this->update([
            'had_onboarding_call' => true,
            'onboarding_call_confirmed_by' => $confirmedBy->id,
            'onboarding_call_confirmed_at' => now(),
        ]);
    }

    public function onboardingCallConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'onboarding_call_confirmed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
