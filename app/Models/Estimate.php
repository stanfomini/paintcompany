<?php

namespace App\Models;

use App\Services\EstimatePackageGeneratorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Estimate extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateFactory> */
    use HasFactory;

    public const MODE_SIMPLE = 'simple';

    public const MODE_TEMPLATE = 'template';

    public const MODE_DETAILED = 'detailed';

    public const PROJECT_TYPE_INTERIOR = 'interior';

    public const PROJECT_TYPE_EXTERIOR = 'exterior';

    public const PROJECT_TYPE_BOTH = 'both';

    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ];

    protected $fillable = [
        'deal_id',
        'created_by',
        'estimate_number',
        'title',
        'description',
        'status',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'total',
        'areas_subtotal',
        'items_subtotal',
        'options_subtotal',
        'notes',
        'terms',
        'valid_until',
        'sent_at',
        'accepted_at',
        'rejected_at',
        'public_token',
        'public_token_expires_at',
        'estimator_mode',
        'simple_sqft',
        'simple_rate_per_sqft',
        'project_type',
        'deposit_percent',
        'deposit_amount',
        'balance_due',
        'selected_package_id',
        'default_paint_product_id',
        'paint_products_by_category',
        'settings_snapshot',
        'actual_paint_costs',
        'actual_painters',
        'profit_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'areas_subtotal' => 'decimal:2',
            'items_subtotal' => 'decimal:2',
            'options_subtotal' => 'decimal:2',
            'simple_sqft' => 'decimal:2',
            'simple_rate_per_sqft' => 'decimal:2',
            'deposit_percent' => 'decimal:2',
            'deposit_amount' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'public_token_expires_at' => 'datetime',
            'paint_products_by_category' => 'array',
            'settings_snapshot' => 'array',
            'actual_paint_costs' => 'array',
            'actual_painters' => 'array',
            'profit_snapshot' => 'array',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::creating(function (Estimate $estimate) {
            if (empty($estimate->estimate_number)) {
                $estimate->estimate_number = static::generateEstimateNumber();
            }

            // Capture company settings snapshot at creation time
            if (empty($estimate->settings_snapshot)) {
                $estimate->settings_snapshot = $estimate->captureSettingsSnapshot();
            }
        });
    }

    /**
     * Capture current company settings for this estimate.
     * Used for comparing original vs current settings in breakdown sheets.
     *
     * @return array<string, mixed>
     */
    public function captureSettingsSnapshot(): array
    {
        $settings = CompanySetting::getInstance();
        $scope = $this->project_type ?? 'interior';

        return [
            'captured_at' => now()->toIso8601String(),
            'scope' => $scope,
            // Labor Configuration
            'hourly_wage' => $settings->getHourlyWageForScope($scope),
            'payroll_burden_multiplier' => (float) $settings->payroll_burden_multiplier,
            // Margins
            'margin_percent' => $settings->getMarginPercentForScope($scope),
            'net_profit_margin_percent' => (float) $settings->net_profit_margin_percent,
            // Overhead Allocations
            'overhead_percent' => (float) $settings->overhead_percent,
            'marketing_percent' => (float) $settings->marketing_percent,
            'sales_percent' => (float) $settings->sales_percent,
            'production_management_percent' => (float) $settings->production_management_percent,
            // Production Settings
            'cleanup_hours_ratio' => $settings->getCleanupHoursRatioForScope($scope),
            'sundries_cost_per_hour' => $settings->getSundriesCostForScope($scope),
            // Tax Rates
            'material_tax_rate' => $settings->getMaterialTaxRate(),
            'labor_tax_rate' => $settings->getLaborTaxRate(),
            // Other
            'recycle_fee_per_gallon' => (float) $settings->recycle_fee_per_gallon,
        ];
    }

    /**
     * Generate a unique estimate number.
     */
    public static function generateEstimateNumber(): string
    {
        $prefix = 'EST-';
        $year = date('Y');
        $lastEstimate = static::whereYear('created_at', $year)
            ->orderByDesc('id')
            ->first();

        if ($lastEstimate) {
            $lastNumber = (int) substr($lastEstimate->estimate_number, -5);
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }

        return $prefix.$year.'-'.str_pad($newNumber, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Get the status label.
     */
    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /**
     * Get the deal for this estimate.
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Get the default paint product for this estimate.
     */
    public function defaultPaintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class, 'default_paint_product_id');
    }

    /**
     * Get the user who created this estimate.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the line items for this estimate.
     */
    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('sort_order');
    }

    /**
     * Get the areas for this estimate.
     */
    public function areas(): HasMany
    {
        return $this->hasMany(EstimateArea::class)->orderBy('sort_order');
    }

    /**
     * Get the options for this estimate.
     */
    public function options(): HasMany
    {
        return $this->hasMany(EstimateOption::class)->orderBy('sort_order');
    }

    /**
     * Get the photos for this estimate.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(EstimatePhoto::class)->orderBy('sort_order');
    }

    /**
     * Get the estimate notes for this estimate.
     * Named `estimateNotes` to avoid conflict with the `notes` text column.
     */
    public function estimateNotes(): HasMany
    {
        return $this->hasMany(EstimateNote::class);
    }

    /**
     * Get the signature for this estimate.
     */
    public function signature(): HasOne
    {
        return $this->hasOne(EstimateSignature::class);
    }

    /**
     * Get the job created from this estimate.
     */
    public function job(): HasOne
    {
        return $this->hasOne(Job::class);
    }

    /**
     * Get the activities for this estimate.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(EstimateActivity::class)->orderByDesc('created_at');
    }

    /**
     * Get the views for this estimate.
     */
    public function views(): HasMany
    {
        return $this->hasMany(EstimateView::class);
    }

    /**
     * Get the packages for this estimate.
     */
    public function packages(): HasMany
    {
        return $this->hasMany(EstimatePackage::class);
    }

    /**
     * Get the selected package for this estimate.
     */
    public function selectedPackage(): BelongsTo
    {
        return $this->belongsTo(EstimatePackage::class, 'selected_package_id');
    }

    /**
     * Get the template usages for this estimate.
     */
    public function templateUsages(): HasMany
    {
        return $this->hasMany(EstimateTemplateUsage::class);
    }

    /**
     * Get the optional items for this estimate.
     */
    public function optionalItems(): HasMany
    {
        return $this->hasMany(EstimateOptionalItem::class);
    }

    // =========================================================================
    // PRICING METHODS - CLEAR SEPARATION OF COGS vs CUSTOMER PRICE
    // =========================================================================
    // ARCHITECTURE:
    // - Surfaces store COGS only (labor + material) - NO margins
    // - Margin is applied ONCE at the AREA level via EstimateArea::getCustomerPrice()
    // - Each area uses its scope-specific margin (interior/exterior)
    // - Estimate sums area customer prices + items + options - NO additional margin
    // =========================================================================

    /**
     * Calculate totals from items, areas, and options.
     *
     * Margin is applied PER AREA via EstimateArea::getCustomerPrice().
     * Each area uses its scope-specific margin (interior/exterior).
     * This method simply sums the results - no additional margin applied here.
     */
    public function calculateTotals(): void
    {
        $this->items_subtotal = $this->items()->sum('total');
        $this->areas_subtotal = $this->calculateAreasSubtotal();
        $this->options_subtotal = $this->getSelectedOptionsTotal();

        // Sum everything - margins already applied at area level in areas_subtotal
        $this->subtotal = (float) $this->items_subtotal + (float) $this->areas_subtotal + (float) $this->options_subtotal;

        $this->tax_amount = (float) $this->subtotal * ((float) $this->tax_rate / 100);
        $this->total = (float) $this->subtotal + (float) $this->tax_amount - (float) $this->discount_amount;
    }

    /**
     * Get the raw COGS (Cost of Goods Sold) for this entire estimate.
     * Sum of all area COGS (before margin) + items + options.
     */
    public function getCOGS(): float
    {
        $areaCogs = 0.0;
        foreach ($this->areas as $area) {
            $areaCogs += (float) $area->surfaces_total; // Raw surface COGS
        }

        return $areaCogs + (float) $this->items_subtotal + (float) $this->options_subtotal;
    }

    /**
     * Get the base cost before margins are applied.
     * ALIAS for getCOGS() - kept for backward compatibility.
     */
    public function getBaseCost(): float
    {
        return $this->getCOGS();
    }

    /**
     * Get the customer-facing price for this estimate.
     * This is the stored subtotal (which includes margins).
     */
    public function getCustomerPrice(): float
    {
        return (float) $this->subtotal;
    }

    /**
     * Get the margin amount (difference between subtotal and base cost).
     */
    public function getMarginAmount(): float
    {
        return (float) $this->subtotal - $this->getCOGS();
    }

    /**
     * Get the margin percentage for this estimate based on stored values.
     */
    public function getMarginPercent(): float
    {
        $subtotal = (float) $this->subtotal;
        $cogs = $this->getCOGS();

        if ($subtotal <= 0) {
            return 0.0;
        }

        // margin% = 1 - (COGS / subtotal)
        return round((1 - ($cogs / $subtotal)) * 100, 2);
    }

    /**
     * Get the total of selected options.
     * Uses calculated_price which includes the computed value from production rates.
     */
    public function getSelectedOptionsTotal(): float
    {
        return (float) $this->options()->where('is_selected', true)->sum('calculated_price');
    }

    /**
     * Calculate areas subtotal with margin applied per area.
     * Each area applies its scope-specific margin (interior/exterior).
     * Returns the customer-facing total for all areas.
     */
    public function calculateAreasSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->areas as $area) {
            $total += $area->getCustomerPrice();
        }

        return round($total, 2);
    }

    /**
     * Generate a unique public token for sharing the estimate.
     */
    public function generatePublicToken(int $expiryDays = 30): string
    {
        $this->public_token = Str::random(64);
        $this->public_token_expires_at = now()->addDays($expiryDays);
        $this->save();

        return $this->public_token;
    }

    /**
     * Get the public URL for this estimate.
     */
    public function getPublicUrl(): ?string
    {
        if (! $this->public_token) {
            return null;
        }

        return route('estimates.public', ['token' => $this->public_token]);
    }

    /**
     * Check if the public token is valid.
     */
    public function isPublicTokenValid(): bool
    {
        return $this->public_token
            && $this->public_token_expires_at
            && $this->public_token_expires_at->isFuture();
    }

    /**
     * Invalidate the public token.
     */
    public function invalidatePublicToken(): void
    {
        $this->public_token = null;
        $this->public_token_expires_at = null;
        $this->save();
    }

    /**
     * Check if estimate has a signature.
     */
    public function hasSignature(): bool
    {
        return $this->signature()->exists();
    }

    /**
     * Check if estimate requires a signature.
     */
    public function requiresSignature(): bool
    {
        return true;
    }

    /**
     * Check if the authenticated internal user has a signature configured.
     * Used to gate sending proposals - the sender's signature appears on the proposal.
     */
    public function hasCreatorSignature(): bool
    {
        $user = auth()->user();
        if (! $user || $user->type !== 'internal') {
            return false;
        }

        $internalUser = $user->internalUser;

        return $internalUser && $internalUser->hasSignature();
    }

    /**
     * Mark estimate as sent.
     * Generates packages from templates if applicable.
     */
    public function markAsSent(): bool
    {
        if (! $this->public_token) {
            $this->generatePublicToken();
        }

        // Generate packages from templates if no packages exist
        $this->generatePackagesFromTemplates();

        $this->status = 'sent';
        $this->sent_at = now();

        return $this->save();
    }

    /**
     * Generate estimate packages from package templates if applicable.
     * Only generates if no packages exist and templates are active.
     *
     * @return \Illuminate\Support\Collection<int, EstimatePackage>
     */
    public function generatePackagesFromTemplates(): \Illuminate\Support\Collection
    {
        $generator = app(EstimatePackageGeneratorService::class);

        if (! $generator->shouldUseTemplates($this)) {
            return collect();
        }

        return $generator->generateFromTemplates($this);
    }

    /**
     * Regenerate packages from templates (clears existing and recreates).
     *
     * @return \Illuminate\Support\Collection<int, EstimatePackage>
     */
    public function regeneratePackagesFromTemplates(): \Illuminate\Support\Collection
    {
        $generator = app(EstimatePackageGeneratorService::class);

        return $generator->regenerateFromTemplates($this);
    }

    /**
     * Mark estimate as accepted.
     *
     * @param  bool  $force  Whether to bypass signature requirement (for internal staff)
     *
     * @throws InvalidArgumentException
     */
    public function markAsAccepted(bool $force = false): bool
    {
        if (! $force && $this->requiresSignature() && ! $this->hasSignature()) {
            throw new InvalidArgumentException('Estimate requires a signature before it can be accepted.');
        }

        $this->status = 'accepted';
        $this->accepted_at = now();

        return $this->save();
    }

    /**
     * Mark estimate as rejected.
     */
    public function markAsRejected(): bool
    {
        $this->status = 'rejected';
        $this->rejected_at = now();

        return $this->save();
    }

    /**
     * Check if estimate is editable.
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Check if estimate is expired.
     */
    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    /**
     * Scope to filter by status.
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter draft estimates.
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to filter sent estimates.
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope to filter accepted estimates.
     */
    public function scopeAccepted($query)
    {
        return $query->where('status', 'accepted');
    }

    /**
     * Scope to search estimates.
     */
    public function scopeSearch($query, ?string $search)
    {
        if (empty($search)) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('estimate_number', 'like', "%{$search}%")
                ->orWhere('title', 'like', "%{$search}%");
        });
    }

    /**
     * Scope to find estimate by public token.
     */
    public function scopeByPublicToken($query, string $token)
    {
        return $query->where('public_token', $token);
    }

    /**
     * Check if estimate is in simple mode.
     */
    public function isSimpleMode(): bool
    {
        return $this->estimator_mode === self::MODE_SIMPLE;
    }

    /**
     * Check if estimate is in template mode.
     */
    public function isTemplateMode(): bool
    {
        return $this->estimator_mode === self::MODE_TEMPLATE;
    }

    /**
     * Check if estimate is in detailed mode.
     */
    public function isDetailedMode(): bool
    {
        return $this->estimator_mode === self::MODE_DETAILED;
    }

    /**
     * Calculate totals for simple mode estimates.
     */
    public function calculateSimpleModeTotal(): void
    {
        $this->subtotal = (float) $this->simple_sqft * (float) $this->simple_rate_per_sqft;
        $this->tax_amount = $this->subtotal * ((float) $this->tax_rate / 100);
        $this->total = $this->subtotal + $this->tax_amount - (float) $this->discount_amount;
    }

    /**
     * Calculate deposit and balance due based on total and deposit percentage.
     */
    public function calculateDepositAndBalance(): void
    {
        $this->deposit_amount = (float) $this->total * ((float) $this->deposit_percent / 100);
        $this->balance_due = (float) $this->total - (float) $this->deposit_amount;
    }

    /**
     * Sync paint products from paint_products_by_category to surfaces that are missing them.
     * Iterates all areas/surfaces, resolves paint product from category mapping,
     * sets paint_product_id, and recalculates material_cost.
     *
     * @return int Number of surfaces updated
     */
    public function syncPaintProductsToSurfaces(): int
    {
        $updated = 0;

        foreach ($this->areas()->with('surfaces')->get() as $area) {
            foreach ($area->surfaces as $surface) {
                // Skip surfaces that already have a paint product assigned
                if ($surface->paint_product_id) {
                    continue;
                }

                $category = $surface->getSurfaceCategory();

                // "other" category doesn't have a paint product mapping
                if ($category === 'other') {
                    continue;
                }

                $paintProductId = $this->getPaintProductIdForCategory($category);

                if (! $paintProductId) {
                    continue;
                }

                $surface->paint_product_id = $paintProductId;
                $surface->material_cost = $surface->calculatePaintMaterialCost();
                $surface->total = (float) $surface->labor_cost + (float) $surface->material_cost;
                $surface->saveQuietly();
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Get the paint product ID for a specific surface category.
     * Implements cascade: Estimate override → Company default → null
     *
     * @param  string  $category  Surface category (walls, ceiling, trim, doors, body, fascia, eaves)
     * @return int|null Paint product ID or null
     */
    public function getPaintProductIdForCategory(string $category): ?int
    {
        // 1. Check estimate-level override
        $estimateOverrides = $this->paint_products_by_category ?? [];
        $scope = $this->project_type ?? 'interior';

        if (isset($estimateOverrides[$scope][$category])) {
            return $estimateOverrides[$scope][$category];
        }

        // 2. Check company-level default
        $settings = CompanySetting::getInstance();

        return $settings->getDefaultPaintForCategory($category, $scope);
    }

    /**
     * Resolve the paint product for a specific surface category.
     * Implements cascade: Estimate override → Company default → null
     *
     * @param  string  $category  Surface category (walls, ceiling, trim, doors, body, fascia, eaves)
     * @return PaintProduct|null The resolved paint product or null
     */
    public function resolvePaintForCategory(string $category): ?PaintProduct
    {
        $paintProductId = $this->getPaintProductIdForCategory($category);

        if (! $paintProductId) {
            return null;
        }

        return PaintProduct::find($paintProductId);
    }

    /**
     * Set the paint product for a specific surface category (estimate-level override).
     *
     * @param  string  $category  Surface category
     * @param  int|null  $paintProductId  Paint product ID or null to remove
     */
    public function setPaintForCategory(string $category, ?int $paintProductId): void
    {
        $overrides = $this->paint_products_by_category ?? [];
        $scope = $this->project_type ?? 'interior';

        if (! isset($overrides[$scope])) {
            $overrides[$scope] = [];
        }

        if ($paintProductId === null) {
            unset($overrides[$scope][$category]);

            // Remove scope key if empty
            if (empty($overrides[$scope])) {
                unset($overrides[$scope]);
            }
        } else {
            $overrides[$scope][$category] = $paintProductId;
        }

        $this->paint_products_by_category = $overrides;
    }

    /**
     * Get all paint overrides for the current project scope.
     *
     * @return array<string, int> Category => paint product ID mapping
     */
    public function getPaintOverridesForScope(): array
    {
        $overrides = $this->paint_products_by_category ?? [];
        $scope = $this->project_type ?? 'interior';

        return $overrides[$scope] ?? [];
    }

    /**
     * Check if there are any paint overrides set for the current scope.
     */
    public function hasPaintOverrides(): bool
    {
        return ! empty($this->getPaintOverridesForScope());
    }

    /**
     * Get the count of paint category overrides for the current scope.
     */
    public function getPaintOverrideCount(): int
    {
        return count($this->getPaintOverridesForScope());
    }
}
