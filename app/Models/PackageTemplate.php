<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\PackageTemplateFactory> */
    use HasFactory;

    public const TIER_GOOD = 'good';

    public const TIER_BETTER = 'better';

    public const TIER_BEST = 'best';

    protected $fillable = [
        'name',
        'tier',
        'description',
        'warranty_months',
        'warranty_percent',
        'is_default',
        'is_popular',
        'is_active',
        'sort_order',
        'service_category_id',
    ];

    protected function casts(): array
    {
        return [
            'warranty_months' => 'integer',
            'warranty_percent' => 'decimal:2',
            'is_default' => 'boolean',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the service category for this package template.
     */
    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    /**
     * Get the items directly associated with this package template (legacy relationship).
     */
    public function items(): HasMany
    {
        return $this->hasMany(PackageTemplateItem::class)->orderBy('sort_order');
    }

    /**
     * Get the items that default to this package template (many-to-many via pivot).
     */
    public function defaultItems(): BelongsToMany
    {
        return $this->belongsToMany(
            PackageTemplateItem::class,
            'package_template_item_defaults',
            'package_template_id',
            'package_template_item_id'
        )->withTimestamps()->orderBy('sort_order');
    }

    /**
     * Scope to filter by service category.
     */
    public function scopeForService(Builder $query, ?ServiceCategory $category): Builder
    {
        if ($category) {
            return $query->where('service_category_id', $category->id);
        }

        return $query;
    }

    /**
     * Calculate the warranty cost based on a job total.
     */
    public function calculateWarrantyCost(float $paintingTotal): float
    {
        return $paintingTotal * ((float) $this->warranty_percent / 100);
    }

    /**
     * Calculate the total package price including all items.
     */
    public function calculatePackageTotal(float $paintingTotal): float
    {
        $total = $paintingTotal;

        // Add warranty cost
        $total += $this->calculateWarrantyCost($paintingTotal);

        // Add item costs
        foreach ($this->items()->where('is_included_by_default', true)->get() as $item) {
            $total += $item->calculatePrice($paintingTotal);
        }

        return $total;
    }

    /**
     * Get the warranty description text.
     */
    public function getWarrantyDescriptionAttribute(): string
    {
        if ($this->warranty_months <= 0) {
            return 'No warranty';
        }

        if ($this->warranty_months === 12) {
            return '1-Year Warranty';
        }

        if ($this->warranty_months > 12) {
            $years = floor($this->warranty_months / 12);

            return $years.'-Year Warranty';
        }

        return $this->warranty_months.'-Month Warranty';
    }

    /**
     * Scope to active templates only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get the default package template.
     */
    public static function getDefault(): ?self
    {
        return static::active()->where('is_default', true)->first();
    }

    /**
     * Get all available tiers.
     *
     * @return array<string, string>
     */
    public static function getTiers(): array
    {
        return [
            self::TIER_GOOD => 'Good',
            self::TIER_BETTER => 'Better',
            self::TIER_BEST => 'Best',
        ];
    }
}
