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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }
}
