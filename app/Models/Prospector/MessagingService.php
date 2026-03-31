<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MessagingService extends Model
{
    use HasFactory;

    protected $table = 'prospector_messaging_services';

    protected $fillable = [
        'ulid',
        'twilio_account_id',
        'company_id',
        'twilio_sid',
        'friendly_name',
        'status',
        'brand_sid',
        'brand_status',
        'brand_details',
        'campaign_sid',
        'campaign_status',
        'campaign_use_case',
        'campaign_details',
        'phone_number_count',
    ];

    protected function casts(): array
    {
        return [
            'brand_details' => 'array',
            'campaign_details' => 'array',
            'phone_number_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MessagingService $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
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
    // Methods
    // ──────────────────────────────────────

    public function isBrandApproved(): bool
    {
        return $this->brand_status === 'APPROVED';
    }

    public function isCampaignVerified(): bool
    {
        return $this->campaign_status === 'VERIFIED';
    }

    public function isFullyCompliant(): bool
    {
        return $this->isBrandApproved() && $this->isCampaignVerified();
    }

    public function getBrandStatusColor(): string
    {
        return match ($this->brand_status) {
            'APPROVED' => 'green',
            'PENDING', 'IN_REVIEW' => 'amber',
            'FAILED', 'SUSPENDED' => 'red',
            default => 'zinc',
        };
    }

    public function getCampaignStatusColor(): string
    {
        return match ($this->campaign_status) {
            'VERIFIED' => 'green',
            'IN_PROGRESS' => 'amber',
            'FAILED' => 'red',
            default => 'zinc',
        };
    }
}
