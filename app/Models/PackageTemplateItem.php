<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageTemplateItem extends Model
{
    /** @use HasFactory<\Database\Factories\PackageTemplateItemFactory> */
    use HasFactory;

    public const PRICING_FIXED = 'fixed';

    public const PRICING_PERCENT = 'percent';

    protected $fillable = [
        'package_template_id',
        'name',
        'description',
        'pricing_type',
        'cost',
        'price',
        'percent',
        'is_optional',
        'is_included_by_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'percent' => 'decimal:2',
            'is_optional' => 'boolean',
            'is_included_by_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the package template this item belongs to.
     */
    public function packageTemplate(): BelongsTo
    {
        return $this->belongsTo(PackageTemplate::class);
    }

    /**
     * Calculate the price for this item based on a painting total.
     */
    public function calculatePrice(float $paintingTotal): float
    {
        if ($this->pricing_type === self::PRICING_PERCENT) {
            return $paintingTotal * ((float) $this->percent / 100);
        }

        return (float) $this->price;
    }

    /**
     * Get the pricing type label.
     */
    public function getPricingTypeLabelAttribute(): string
    {
        return match ($this->pricing_type) {
            self::PRICING_FIXED => 'Fixed Price',
            self::PRICING_PERCENT => 'Percentage',
            default => $this->pricing_type,
        };
    }

    /**
     * Get the price display string.
     */
    public function getPriceDisplayAttribute(): string
    {
        if ($this->pricing_type === self::PRICING_PERCENT) {
            return number_format((float) $this->percent, 1).'%';
        }

        return '$'.number_format((float) $this->price, 2);
    }

    /**
     * Get available pricing types.
     *
     * @return array<string, string>
     */
    public static function getPricingTypes(): array
    {
        return [
            self::PRICING_FIXED => 'Fixed Price',
            self::PRICING_PERCENT => 'Percentage of Job',
        ];
    }
}
