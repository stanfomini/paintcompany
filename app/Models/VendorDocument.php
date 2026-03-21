<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class VendorDocument extends Model
{
    use HasFactory;

    public const TYPE_W9 = 'w9';

    public const TYPE_INSURANCE = 'insurance';

    public const TYPE_WORKERS_COMP = 'workers_comp';

    public const TYPE_HOLD_HARMLESS = 'hold_harmless';

    public const TYPE_LICENSE = 'license';

    public const TYPE_CONTRACT = 'contract';

    public const TYPE_OTHER = 'other';

    public const TYPES = [
        self::TYPE_W9 => 'W-9',
        self::TYPE_INSURANCE => 'Certificate of Insurance',
        self::TYPE_WORKERS_COMP => 'Workers Comp Certificate',
        self::TYPE_HOLD_HARMLESS => 'Hold Harmless Agreement',
        self::TYPE_LICENSE => 'License / Certification',
        self::TYPE_CONTRACT => 'Vendor Contract',
        self::TYPE_OTHER => 'Other',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Pending Review',
        self::STATUS_APPROVED => 'Approved',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'vendor_id',
        'vendor_contract_id',
        'uploaded_by',
        'name',
        'type',
        'file_path',
        'original_filename',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'notes',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
            'expires_at' => 'date',
        ];
    }

    // ─── Relationships ───

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function vendorContract(): BelongsTo
    {
        return $this->belongsTo(VendorContract::class);
    }

    // ─── Scopes ───

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    // ─── Helpers ───

    public function getTypeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function getStatusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function approve(User $reviewer, ?string $notes = null): self
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return $this;
    }

    public function reject(User $reviewer, ?string $notes = null): self
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);

        return $this;
    }

    public function getDownloadUrl(): ?string
    {
        $disk = Storage::disk();

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($this->file_path, now()->addMinutes(30));
            } catch (\RuntimeException) {
                // Local disk doesn't support temporaryUrl
            }
        }

        return null;
    }

    public function getFileExtension(): string
    {
        return pathinfo($this->original_filename ?? $this->file_path, PATHINFO_EXTENSION);
    }
}
