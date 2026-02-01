<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EstimateTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateTemplateFactory> */
    use HasFactory;

    public const PRICING_PRODUCTION_RATE = 'production_rate';

    public const PRICING_FIXED = 'fixed';

    public const PRICING_PER_SQFT = 'per_sqft';

    public const PRICING_PERCENT = 'percent';

    public const PRICING_METHODS = [
        self::PRICING_PRODUCTION_RATE,
        self::PRICING_FIXED,
        self::PRICING_PER_SQFT,
        self::PRICING_PERCENT,
    ];

    public const SCOPE_TYPE_INTERIOR = 'interior';

    public const SCOPE_TYPE_EXTERIOR = 'exterior';

    public const SCOPE_TYPE_BOTH = 'both';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_COMPANY = 'company';

    public const VISIBILITY_SYSTEM = 'system';

    public const VISIBILITIES = [
        self::VISIBILITY_PRIVATE,
        self::VISIBILITY_COMPANY,
        self::VISIBILITY_SYSTEM,
    ];

    protected $fillable = [
        'template_category_id',
        'name',
        'slug',
        'description',
        'icon',
        'image_path',
        'default_quantity',
        'default_unit',
        'default_coats',
        'production_rate_id',
        'substrate_id',
        'default_paint_product_id',
        'pricing_method',
        'base_price',
        'per_sqft_rate',
        'percent_of_total',
        'scope_type',
        'default_surfaces',
        'options',
        'metadata',
        'is_active',
        'created_by',
        'visibility',
        'is_configurable',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_quantity' => 'decimal:2',
            'default_coats' => 'integer',
            'base_price' => 'decimal:2',
            'per_sqft_rate' => 'decimal:4',
            'percent_of_total' => 'decimal:2',
            'default_surfaces' => 'array',
            'options' => 'array',
            'metadata' => 'array',
            'is_active' => 'boolean',
            'is_configurable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (EstimateTemplate $template) {
            if (empty($template->slug)) {
                $template->slug = static::generateUniqueSlug($template->name);
            }
        });

        static::updating(function (EstimateTemplate $template) {
            if ($template->isDirty('name') && ! $template->isDirty('slug')) {
                $template->slug = static::generateUniqueSlug($template->name, $template->id);
            }
        });
    }

    /**
     * Generate a unique slug from the given name.
     */
    public static function generateUniqueSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);
        $originalSlug = $slug;
        $counter = 1;

        $query = static::where('slug', $slug);
        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        while ($query->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;

            $query = static::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
        }

        return $slug;
    }

    /**
     * Get the template category for this template.
     */
    public function templateCategory(): BelongsTo
    {
        return $this->belongsTo(EstimateTemplateCategory::class);
    }

    /**
     * Get the production rate for this template.
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * Get the substrate for this template.
     */
    public function substrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class);
    }

    /**
     * Get the default paint product for this template.
     */
    public function defaultPaintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class, 'default_paint_product_id');
    }

    /**
     * Get the user who created this template.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the surfaces for this area template.
     */
    public function surfaces(): HasMany
    {
        return $this->hasMany(AreaTemplateSurface::class)->orderBy('sort_order');
    }

    /**
     * Get the nested options for this area template.
     */
    public function nestedOptions(): HasMany
    {
        return $this->hasMany(AreaTemplateNestedOption::class, 'parent_template_id')->orderBy('sort_order');
    }

    /**
     * Scope to only include active templates.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeByCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('template_category_id', $categoryId);
    }

    /**
     * Scope to only include configurable area templates.
     */
    public function scopeConfigurable(Builder $query): Builder
    {
        return $query->where('is_configurable', true);
    }

    /**
     * Scope to only include fixed-price (non-configurable) templates.
     */
    public function scopeFixedPrice(Builder $query): Builder
    {
        return $query->where('is_configurable', false);
    }

    /**
     * Scope to only include system templates.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_SYSTEM);
    }

    /**
     * Scope to only include company templates.
     */
    public function scopeCompany(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_COMPANY);
    }

    /**
     * Scope to templates visible to a user.
     * System templates are visible to all.
     * Company templates are visible to all users in the same company.
     * Private templates are only visible to the creator.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user) {
            // System templates are visible to all
            $q->where('visibility', self::VISIBILITY_SYSTEM)
                // Company templates visible to all (single company system)
                ->orWhere('visibility', self::VISIBILITY_COMPANY)
                // Private templates visible only to creator
                ->orWhere(function (Builder $q2) use ($user) {
                    $q2->where('visibility', self::VISIBILITY_PRIVATE)
                        ->where('created_by', $user->id);
                });
        });
    }

    /**
     * Scope to filter by scope type (interior/exterior).
     */
    public function scopeByScope(Builder $query, string $scopeType): Builder
    {
        return $query->where(function (Builder $q) use ($scopeType) {
            $q->where('scope_type', $scopeType)
                ->orWhere('scope_type', self::SCOPE_TYPE_BOTH);
        });
    }

    /**
     * Calculate the price for a given quantity and number of coats.
     *
     * @param  float  $quantity  The quantity (sqft, units, etc.)
     * @param  int|null  $coats  Number of coats (optional, uses default if not provided)
     */
    public function calculatePrice(float $quantity, ?int $coats = null): float
    {
        $coats = $coats ?? $this->default_coats ?? 1;

        return match ($this->pricing_method) {
            self::PRICING_PRODUCTION_RATE => $this->calculateProductionRatePrice($quantity, $coats),
            self::PRICING_FIXED => (float) ($this->base_price ?? 0),
            self::PRICING_PER_SQFT => $quantity * (float) ($this->per_sqft_rate ?? 0),
            self::PRICING_PERCENT => 0.0, // Calculated externally based on total
            default => 0.0,
        };
    }

    /**
     * Calculate price using the linked production rate.
     * Returns BASE COST only - margins are applied at the Estimate level.
     */
    protected function calculateProductionRatePrice(float $quantity, int $coats): float
    {
        if (! $this->productionRate) {
            return 0.0;
        }

        return $this->productionRate->calculatePrice($quantity, $coats);
    }

    /**
     * Get the human-readable pricing method label.
     */
    public function getPricingMethodLabelAttribute(): string
    {
        return match ($this->pricing_method) {
            self::PRICING_PRODUCTION_RATE => 'Production Rate',
            self::PRICING_FIXED => 'Fixed Price',
            self::PRICING_PER_SQFT => 'Per Square Foot',
            self::PRICING_PERCENT => 'Percentage of Total',
            default => $this->pricing_method,
        };
    }

    /**
     * Get the human-readable scope type label.
     */
    public function getScopeTypeLabelAttribute(): ?string
    {
        if (! $this->scope_type) {
            return null;
        }

        return match ($this->scope_type) {
            self::SCOPE_TYPE_INTERIOR => 'Interior',
            self::SCOPE_TYPE_EXTERIOR => 'Exterior',
            self::SCOPE_TYPE_BOTH => 'Interior & Exterior',
            default => $this->scope_type,
        };
    }

    /**
     * Get the human-readable visibility label.
     */
    public function getVisibilityLabelAttribute(): string
    {
        return match ($this->visibility) {
            self::VISIBILITY_PRIVATE => 'Private',
            self::VISIBILITY_COMPANY => 'Company',
            self::VISIBILITY_SYSTEM => 'System',
            default => ucfirst($this->visibility ?? 'Unknown'),
        };
    }

    /**
     * Check if this template has model-based surfaces defined.
     * Used for backward compatibility with JSON metadata surfaces.
     */
    public function hasModelSurfaces(): bool
    {
        return $this->surfaces()->exists();
    }

    /**
     * Check if this is a system template (cannot be deleted/modified by users).
     */
    public function isSystemTemplate(): bool
    {
        return $this->visibility === self::VISIBILITY_SYSTEM;
    }

    /**
     * Check if this template can be modified by the given user.
     */
    public function canBeModifiedBy(User $user): bool
    {
        // System templates cannot be modified
        if ($this->isSystemTemplate()) {
            return false;
        }

        // Private templates can only be modified by creator
        if ($this->visibility === self::VISIBILITY_PRIVATE) {
            return $this->created_by === $user->id;
        }

        // Company templates can be modified by any company user
        return true;
    }

    /**
     * Check if this template can be deleted by the given user.
     */
    public function canBeDeletedBy(User $user): bool
    {
        return $this->canBeModifiedBy($user);
    }
}
