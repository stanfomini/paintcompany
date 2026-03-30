<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneNumber extends Model
{
    use HasFactory;

    protected $table = 'prospector_phone_numbers';

    protected $fillable = [
        'twilio_account_id',
        'company_id',
        'phone_number',
        'friendly_name',
        'area_code',
        'twilio_sid',
        'role',
        'caller_id_override',
        'capabilities',
        'inbound_action',
        'inbound_forward_to',
        'monthly_cost',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'is_active' => 'boolean',
            'monthly_cost' => 'decimal:4',
            'last_used_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────
    // Relations
    // ──────────────────────────────────────

    public function twilioAccount(): BelongsTo
    {
        return $this->belongsTo(TwilioAccount::class, 'twilio_account_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    // ──────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('company_id');
    }

    public function scopeForCompany(Builder $query, int $companyId): void
    {
        $query->where('company_id', $companyId);
    }

    public function scopeWithRole(Builder $query, string $role): void
    {
        $query->where('role', $role);
    }

    public function scopeInAreaCode(Builder $query, string $areaCode): void
    {
        $query->where('area_code', $areaCode);
    }

    // ──────────────────────────────────────
    // Methods
    // ──────────────────────────────────────

    /**
     * Check if this is the main company number.
     */
    public function isMain(): bool
    {
        return $this->role === 'main';
    }

    /**
     * Check if this number is assigned to a company.
     */
    public function isAssigned(): bool
    {
        return $this->company_id !== null;
    }

    /**
     * Format the phone number for display as (720) 555-1234.
     */
    public function getDisplayNumber(): string
    {
        $digits = preg_replace('/\D/', '', $this->phone_number);

        // Strip leading country code (1 for US)
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return sprintf(
                '(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 4)
            );
        }

        return $this->phone_number;
    }

    /**
     * Get the effective caller ID for outbound calls.
     *
     * Returns the override if set, otherwise the actual phone number.
     */
    public function getEffectiveCallerId(): string
    {
        return $this->caller_id_override ?: $this->phone_number;
    }

    /**
     * Mark this number as recently used.
     */
    public function markUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }
}
