<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrewSignedContract extends Model
{
    /** @use HasFactory<\Database\Factories\CrewSignedContractFactory> */
    use HasFactory;

    public const TYPE_COLORADO_COMPLIANCE = 'colorado_compliance';

    public const TYPE_CONTRACTOR_AGREEMENT = 'contractor_agreement';

    public const TYPE_BACKGROUND_CHECK = 'background_check_consent';

    public const TYPES = [
        self::TYPE_COLORADO_COMPLIANCE => 'Colorado Contractor Compliance',
        self::TYPE_CONTRACTOR_AGREEMENT => 'Contractor Agreement',
        self::TYPE_BACKGROUND_CHECK => 'Background Check Consent',
    ];

    protected $fillable = [
        'user_id',
        'contract_type',
        'contract_content',
        'signature_data',
        'signature_type',
        'signer_name',
        'signer_email',
        'ip_address',
        'user_agent',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the human-readable label for this contract type.
     */
    public function getTypeLabelAttribute(): string
    {
        return __(self::TYPES[$this->contract_type] ?? $this->contract_type);
    }

    /**
     * Create a signed contract from a request.
     */
    public static function sign(
        User $user,
        string $contractType,
        string $contractContent,
        string $signatureData,
        string $signatureType,
    ): self {
        return static::updateOrCreate(
            [
                'user_id' => $user->id,
                'contract_type' => $contractType,
            ],
            [
                'contract_content' => $contractContent,
                'signature_data' => $signatureData,
                'signature_type' => $signatureType,
                'signer_name' => $user->name,
                'signer_email' => $user->email,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'signed_at' => now(),
            ]
        );
    }
}
