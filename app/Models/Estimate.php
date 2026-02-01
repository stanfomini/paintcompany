<?php

namespace App\Models;

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
        });
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

    /**
     * Calculate totals from items, areas, and options.
     * By default, applies company margins (profit, overhead, markup) to the subtotal.
     *
     * @param  bool  $applyMargins  Whether to apply company margins to base costs
     */
    public function calculateTotals(bool $applyMargins = true): void
    {
        $this->items_subtotal = $this->items()->sum('total');
        $this->areas_subtotal = $this->calculateAreasSubtotal();
        $this->options_subtotal = $this->getSelectedOptionsTotal();

        // Base cost from production rates (labor + materials)
        $baseCost = (float) $this->items_subtotal + (float) $this->areas_subtotal + (float) $this->options_subtotal;

        // Apply company margins (profit, overhead, markup) unless disabled
        if ($applyMargins && $baseCost > 0) {
            $settings = CompanySetting::getInstance();
            $this->subtotal = $settings->calculateWithMargins($baseCost);
        } else {
            $this->subtotal = $baseCost;
        }

        $this->tax_amount = (float) $this->subtotal * ((float) $this->tax_rate / 100);
        $this->total = (float) $this->subtotal + (float) $this->tax_amount - (float) $this->discount_amount;
    }

    /**
     * Get the base cost before margins are applied.
     */
    public function getBaseCost(): float
    {
        return (float) $this->items_subtotal + (float) $this->areas_subtotal + (float) $this->options_subtotal;
    }

    /**
     * Get the margin amount (difference between subtotal and base cost).
     */
    public function getMarginAmount(): float
    {
        return (float) $this->subtotal - $this->getBaseCost();
    }

    /**
     * Get the total of selected options.
     */
    public function getSelectedOptionsTotal(): float
    {
        return (float) $this->options()->where('is_selected', true)->sum('price');
    }

    /**
     * Calculate areas subtotal from all included surfaces within areas.
     */
    public function calculateAreasSubtotal(): float
    {
        $total = 0.0;
        foreach ($this->areas as $area) {
            $total += (float) $area->surfaces_total;
        }

        return $total;
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
     * Check if the creator has a signature configured.
     */
    public function hasCreatorSignature(): bool
    {
        $creator = $this->createdBy;
        if (! $creator || $creator->type !== 'internal') {
            return false;
        }

        $internalUser = $creator->internalUser;

        return $internalUser && $internalUser->hasSignature();
    }

    /**
     * Mark estimate as sent.
     */
    public function markAsSent(): bool
    {
        if (! $this->public_token) {
            $this->generatePublicToken();
        }

        $this->status = 'sent';
        $this->sent_at = now();

        return $this->save();
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
}
