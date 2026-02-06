<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents a warranty reserve for a completed job.
 *
 * When a customer signs a package with a warranty, funds are set aside
 * to cover potential future warranty claims. This model tracks:
 * - The amount reserved
 * - The amount claimed (used for repairs)
 * - The warranty period and expiration
 * - The current status
 */
class WarrantyReserve extends Model
{
    /** @use HasFactory<\Database\Factories\WarrantyReserveFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_FULLY_CLAIMED = 'fully_claimed';

    protected $fillable = [
        'estimate_id',
        'estimate_package_id',
        'job_id',
        'warranty_years',
        'amount_reserved',
        'amount_claimed',
        'warranty_starts_at',
        'warranty_expires_at',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'warranty_years' => 'integer',
            'amount_reserved' => 'decimal:2',
            'amount_claimed' => 'decimal:2',
            'warranty_starts_at' => 'date',
            'warranty_expires_at' => 'date',
        ];
    }

    /**
     * Get the estimate for this warranty.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the package that was signed.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(EstimatePackage::class, 'estimate_package_id');
    }

    /**
     * Get the job for this warranty.
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Scope to pending warranties.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to active warranties.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to expired warranties.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    /**
     * Scope to warranties expiring soon (within 30 days).
     */
    public function scopeExpiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->whereBetween('warranty_expires_at', [now(), now()->addDays($days)]);
    }

    /**
     * Get the remaining reserve balance.
     */
    public function getRemainingBalanceAttribute(): float
    {
        return (float) $this->amount_reserved - (float) $this->amount_claimed;
    }

    /**
     * Get the claim percentage.
     */
    public function getClaimPercentageAttribute(): float
    {
        if ((float) $this->amount_reserved <= 0) {
            return 0;
        }

        return ((float) $this->amount_claimed / (float) $this->amount_reserved) * 100;
    }

    /**
     * Check if the warranty is still valid (active and not expired).
     */
    public function getIsValidAttribute(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->warranty_expires_at->isFuture();
    }

    /**
     * Check if the warranty has available balance for claims.
     */
    public function getHasAvailableBalanceAttribute(): bool
    {
        return $this->remaining_balance > 0;
    }

    /**
     * Get days until expiration (negative if expired).
     */
    public function getDaysUntilExpirationAttribute(): int
    {
        return (int) now()->diffInDays($this->warranty_expires_at, false);
    }

    /**
     * Record a warranty claim.
     *
     * @throws \RuntimeException If warranty is not valid or insufficient balance
     */
    public function recordClaim(float $amount, ?string $notes = null): void
    {
        if (! $this->is_valid) {
            throw new \RuntimeException('Cannot record claim on invalid warranty.');
        }

        if ($amount > $this->remaining_balance) {
            throw new \RuntimeException('Claim amount exceeds available warranty balance.');
        }

        $this->amount_claimed = (float) $this->amount_claimed + $amount;

        if ($notes) {
            $existingNotes = $this->notes ? $this->notes."\n" : '';
            $this->notes = $existingNotes.now()->format('Y-m-d').": Claim of \${$amount} - {$notes}";
        }

        // Check if fully claimed
        if ($this->remaining_balance <= 0) {
            $this->status = self::STATUS_FULLY_CLAIMED;
        }

        $this->save();
    }

    /**
     * Activate the warranty (typically when job is completed).
     */
    public function activate(): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            return;
        }

        $this->status = self::STATUS_ACTIVE;
        $this->warranty_starts_at = now();
        $this->warranty_expires_at = now()->addYears($this->warranty_years);
        $this->save();
    }

    /**
     * Check and update expired warranties.
     */
    public static function expireOldWarranties(): int
    {
        return static::query()
            ->where('status', self::STATUS_ACTIVE)
            ->where('warranty_expires_at', '<', now())
            ->update(['status' => self::STATUS_EXPIRED]);
    }

    /**
     * Create a warranty reserve from a signed package.
     */
    public static function createFromPackage(EstimatePackage $package): ?self
    {
        // Only create if package has warranty
        if (! $package->warranty_years || $package->warranty_years <= 0) {
            return null;
        }

        // Check if warranty already exists for this package
        if (static::where('estimate_package_id', $package->id)->exists()) {
            return static::where('estimate_package_id', $package->id)->first();
        }

        $warrantyPrice = (float) ($package->warranty_price ?? 0);

        return static::create([
            'estimate_id' => $package->estimate_id,
            'estimate_package_id' => $package->id,
            'job_id' => null, // Will be linked when job is created
            'warranty_years' => $package->warranty_years,
            'amount_reserved' => $warrantyPrice,
            'amount_claimed' => 0,
            'warranty_starts_at' => now(), // Tentative, updated when job completes
            'warranty_expires_at' => now()->addYears($package->warranty_years),
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Get available statuses.
     *
     * @return array<string, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_EXPIRED => 'Expired',
            self::STATUS_FULLY_CLAIMED => 'Fully Claimed',
        ];
    }
}
