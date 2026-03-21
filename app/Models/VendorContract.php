<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class VendorContract extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_INTERNAL = 'pending_internal';

    public const STATUS_PENDING_VENDOR = 'pending_vendor';

    public const STATUS_FULLY_EXECUTED = 'fully_executed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_INVALIDATED = 'invalidated';

    public const STATUSES = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PENDING_INTERNAL => 'Pending Internal Signatures',
        self::STATUS_PENDING_VENDOR => 'Pending Vendor Signature',
        self::STATUS_FULLY_EXECUTED => 'Fully Executed',
        self::STATUS_EXPIRED => 'Expired',
        self::STATUS_INVALIDATED => 'Invalidated',
    ];

    protected $fillable = [
        'vendor_id',
        'template_id',
        'title',
        'content',
        'status',
        'is_valid',
        'notes',
        'expires_at',
        'expiry_warning_sent_at',
        'renewed_from_id',
        'public_token',
        'public_token_expires_at',
        'executed_pdf_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_valid' => 'boolean',
            'expires_at' => 'datetime',
            'expiry_warning_sent_at' => 'datetime',
            'public_token_expires_at' => 'datetime',
        ];
    }

    // ─── Relationships ───

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ContractTemplate::class, 'template_id');
    }

    public function signers(): HasMany
    {
        return $this->hasMany(VendorContractSigner::class)->orderBy('order');
    }

    public function vendorSignature(): HasOne
    {
        return $this->hasOne(VendorContractVendorSignature::class);
    }

    public function vendorDocument(): HasOne
    {
        return $this->hasOne(VendorDocument::class);
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renewed_from_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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

    public function invalidatePublicToken(): void
    {
        $this->update([
            'public_token' => null,
            'public_token_expires_at' => null,
        ]);
    }

    public function scopeByPublicToken(Builder $query, string $token): Builder
    {
        return $query->where('public_token', $token);
    }

    // ─── Status Helpers ───

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingInternal(): bool
    {
        return $this->status === self::STATUS_PENDING_INTERNAL;
    }

    public function isPendingVendor(): bool
    {
        return $this->status === self::STATUS_PENDING_VENDOR;
    }

    public function isFullyExecuted(): bool
    {
        return $this->status === self::STATUS_FULLY_EXECUTED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isInvalidated(): bool
    {
        return $this->status === self::STATUS_INVALIDATED;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    // ─── Helpers ───

    public function allInternalSignersSigned(): bool
    {
        $signers = $this->signers;

        if ($signers->isEmpty()) {
            return false;
        }

        return $signers->every(fn (VendorContractSigner $signer) => $signer->hasSigned());
    }

    public function hasActiveRenewal(): bool
    {
        return self::query()
            ->where('renewed_from_id', $this->id)
            ->where('status', '!=', self::STATUS_INVALIDATED)
            ->exists();
    }
}
