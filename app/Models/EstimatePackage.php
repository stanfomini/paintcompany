<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimatePackage extends Model
{
    /** @use HasFactory<\Database\Factories\EstimatePackageFactory> */
    use HasFactory;

    public const TIER_BASIC = 'basic';

    public const TIER_STANDARD = 'standard';

    public const TIER_PREMIUM = 'premium';

    public const TIER_CUSTOM = 'custom';

    public const TIER_LABELS = [
        self::TIER_BASIC => 'Good',
        self::TIER_STANDARD => 'Better',
        self::TIER_PREMIUM => 'Best',
        self::TIER_CUSTOM => 'Custom',
    ];

    protected $fillable = [
        'estimate_id',
        'name',
        'tier',
        'description',
        'includes',
        'excludes',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'discount_percent',
        'total',
        'warranty_price',
        'warranty_years',
        'warranty_type',
        'is_recommended',
        'is_selected',
        'is_visible',
        'sort_order',
        'service_category_id',
        'signature_id',
        'signed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'total' => 'decimal:2',
            'warranty_price' => 'decimal:2',
            'warranty_years' => 'integer',
            'is_recommended' => 'boolean',
            'is_selected' => 'boolean',
            'is_visible' => 'boolean',
            'sort_order' => 'integer',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * Get the estimate for this package.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the service category for this package.
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * Get the signature for this package.
     */
    public function signature(): BelongsTo
    {
        return $this->belongsTo(EstimateSignature::class);
    }

    /**
     * Get the items for this package.
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimatePackageItem::class, 'package_id')->orderBy('sort_order');
    }

    /**
     * Get the paint quality tier for this package tier.
     * Maps package tier to paint quality tier.
     */
    public function getPaintTier(): ?string
    {
        return match ($this->tier) {
            self::TIER_BASIC => 'good',
            self::TIER_STANDARD => 'better',
            self::TIER_PREMIUM => 'best',
            default => null,
        };
    }

    /**
     * Calculate totals using area-level margin (not per-surface margin).
     * Groups surface items by area and uses EstimateArea::getCustomerPrice().
     * Service items (EstimateItem) use their stored total (user-priced).
     * When the estimate has paint tiers, uses tier-specific material costs.
     */
    public function calculateTotals(): void
    {
        $items = $this->items()
            ->included()
            ->approved()
            ->with('itemable')
            ->get();

        $subtotal = 0.0;
        $areaSurfaceIds = []; // areaId => [surfaceId, ...]
        $areaFullIds = []; // areas added via direct EstimateArea items

        // Determine paint tier for this package
        $paintTier = null;
        $estimate = $this->estimate;
        if ($estimate && $estimate->hasPaintTiers()) {
            $paintTier = $this->getPaintTier();
        }

        foreach ($items as $item) {
            $itemable = $item->itemable;
            if (! $itemable) {
                continue;
            }

            if ($item->price_override !== null) {
                $subtotal += (float) $item->price_override;
            } elseif ($itemable instanceof EstimateAreaSurface) {
                // Collect included surface IDs per area for proportional pricing
                $areaId = $itemable->area_id;
                if (! isset($areaFullIds[$areaId])) {
                    $areaSurfaceIds[$areaId][] = $itemable->id;
                }
            } elseif ($itemable instanceof EstimateItem) {
                $subtotal += (float) $itemable->total;
            } elseif ($itemable instanceof EstimateArea) {
                if (! isset($areaFullIds[$itemable->id])) {
                    $areaFullIds[$itemable->id] = true;
                    $subtotal += $itemable->getCustomerPrice($paintTier);
                }
            }
        }

        // Add proportional area pricing for surface-based items
        foreach ($areaSurfaceIds as $areaId => $surfaceIds) {
            if (isset($areaFullIds[$areaId])) {
                continue;
            }
            $area = EstimateArea::find($areaId);
            if ($area) {
                $subtotal += $area->getPartialCustomerPrice($surfaceIds, $paintTier);
            }
        }

        $this->subtotal = round($subtotal, 2);

        // Add warranty price to subtotal
        $baseAmount = $this->subtotal + ($this->warranty_price ?? 0);

        // Calculate discount
        $discountAmount = $this->discount_amount ?? 0;
        if ($this->discount_percent > 0) {
            $discountAmount = $baseAmount * ($this->discount_percent / 100);
        }
        $this->discount_amount = $discountAmount;

        // Calculate subtotal after discount (before tax)
        $taxableAmount = $baseAmount - $discountAmount;

        // Calculate tax
        $this->tax_amount = $taxableAmount * ($this->tax_rate / 100);

        // Calculate total
        $this->total = $taxableAmount + $this->tax_amount;
    }

    /**
     * Get the deposit amount based on the estimate's deposit percent.
     */
    public function getDepositAmountAttribute(): float
    {
        $depositPercent = $this->estimate?->deposit_percent ?? 30;

        return round((float) $this->total * ($depositPercent / 100), 2);
    }

    /**
     * Get the balance due after deposit.
     */
    public function getBalanceDueAttribute(): float
    {
        return round((float) $this->total - $this->deposit_amount, 2);
    }

    /**
     * Get the tier label.
     */
    public function getTierLabelAttribute(): string
    {
        return __(self::TIER_LABELS[$this->tier] ?? $this->tier);
    }

    /**
     * Scope to filter selected packages.
     */
    public function scopeSelected(Builder $query): Builder
    {
        return $query->where('is_selected', true);
    }

    /**
     * Scope to filter visible packages.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    /**
     * Scope to filter recommended packages.
     */
    public function scopeRecommended(Builder $query): Builder
    {
        return $query->where('is_recommended', true);
    }
}
