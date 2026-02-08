<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EstimatePackageItem extends Model
{
    /** @use HasFactory<\Database\Factories\EstimatePackageItemFactory> */
    use HasFactory;

    protected $fillable = [
        'package_id',
        'itemable_type',
        'itemable_id',
        'quantity',
        'price_override',
        'is_included',
        'customer_approved',
        'customer_approved_at',
        'is_customer_selectable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'price_override' => 'decimal:2',
            'is_included' => 'boolean',
            'customer_approved' => 'boolean',
            'customer_approved_at' => 'datetime',
            'is_customer_selectable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saved(function (EstimatePackageItem $item) {
            $item->package->calculateTotals();
            $item->package->save();
        });

        static::deleted(function (EstimatePackageItem $item) {
            $item->package->calculateTotals();
            $item->package->save();
        });
    }

    /**
     * Get the package for this item.
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(EstimatePackage::class, 'package_id');
    }

    /**
     * Get the itemable model (EstimateItem, EstimateArea, or EstimateAreaSurface).
     */
    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to get only included items.
     */
    public function scopeIncluded(Builder $query): Builder
    {
        return $query->where('is_included', true);
    }

    /**
     * Scope to get only customer-approved items.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('customer_approved', true);
    }

    /**
     * Get the effective price for this item (customer-facing price).
     * Returns price_override if set, otherwise calculates from the itemable.
     *
     * IMPORTANT: This returns the CUSTOMER PRICE (with margins applied).
     * Margins are calculated fresh from CompanySetting - never hardcoded.
     */
    public function getEffectivePriceAttribute(): float
    {
        if ($this->price_override !== null) {
            return (float) $this->price_override;
        }

        $itemable = $this->itemable;

        if (! $itemable) {
            return 0.0;
        }

        // EstimateItem has a 'total' attribute (already customer-facing)
        if ($itemable instanceof EstimateItem) {
            return (float) $itemable->total;
        }

        // EstimateAreaSurface - get customer price (COGS + margin from CompanySetting)
        if ($itemable instanceof EstimateAreaSurface) {
            return $itemable->getCustomerPrice();
        }

        // EstimateArea - get customer price (COGS + margin from CompanySetting)
        if ($itemable instanceof EstimateArea) {
            return $itemable->getCustomerPrice();
        }

        return 0.0;
    }

    /**
     * Get the COGS (Cost of Goods Sold) for this item.
     * Returns the raw cost without margins.
     */
    public function getCOGS(): float
    {
        if ($this->price_override !== null) {
            // If price is overridden, we can't know the COGS
            // Return the override as an approximation
            return (float) $this->price_override;
        }

        $itemable = $this->itemable;

        if (! $itemable) {
            return 0.0;
        }

        if ($itemable instanceof EstimateItem) {
            return (float) $itemable->total;
        }

        if ($itemable instanceof EstimateAreaSurface) {
            return $itemable->getCOGS();
        }

        if ($itemable instanceof EstimateArea) {
            return $itemable->getCOGS();
        }

        return 0.0;
    }
}
