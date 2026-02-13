<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewW9Form extends Model
{
    /** @use HasFactory<\Database\Factories\CrewW9FormFactory> */
    use HasFactory;

    public const TAX_CLASSIFICATIONS = [
        'individual' => 'Individual/sole proprietor or single-member LLC',
        'c_corp' => 'C Corporation',
        's_corp' => 'S Corporation',
        'partnership' => 'Partnership',
        'trust' => 'Trust/estate',
        'llc' => 'Limited liability company',
        'other' => 'Other',
    ];

    public const TIN_TYPES = [
        'ssn' => 'Social Security Number',
        'ein' => 'Employer Identification Number',
    ];

    protected $fillable = [
        'user_id',
        'name',
        'business_name',
        'tax_classification',
        'llc_tax_classification',
        'other_classification',
        'exempt_payee_code',
        'fatca_exemption_code',
        'address',
        'city_state_zip',
        'requester_name',
        'account_numbers',
        'tin_type',
        'tin',
        'signature_data',
        'signature_type',
        'signed_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'tin' => 'encrypted',
            'signed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the masked TIN for display (e.g., ***-**-1234).
     */
    public function getMaskedTinAttribute(): string
    {
        $tin = $this->tin;

        if (! $tin) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $tin);

        if ($this->tin_type === 'ssn') {
            return '***-**-'.substr($digits, -4);
        }

        return '**-***'.substr($digits, -4);
    }

    /**
     * Get the human-readable tax classification label.
     */
    public function getTaxClassificationLabelAttribute(): string
    {
        $label = __(self::TAX_CLASSIFICATIONS[$this->tax_classification] ?? $this->tax_classification);

        if ($this->tax_classification === 'llc' && $this->llc_tax_classification) {
            $label .= ' ('.__('taxed as').' '.strtoupper($this->llc_tax_classification).')';
        }

        return $label;
    }
}
