<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobCostCard extends Model
{
    /** @use HasFactory<\Database\Factories\JobCostCardFactory> */
    use HasFactory;

    public const STATUS_PROJECTED = 'projected';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'job_id',
        'estimate_id',
        'company_setting_id',
        'year',
        'client_name',
        'price',
        'production_mgmt_labor',
        'subcontractor_cost',
        'wc_insurance_burden',
        'material_cost',
        'sales_commission',
        'allocation_total',
        'is_manual',
        'status',
        'notes',
        // COGS decomposition
        'labor_cost',
        'cleanup_cost',
        'sundries_cost',
        'material_tax',
        'labor_tax',
        'addon_cost',
        'items_vendor_cost',
        'warranty_reserve',
        'total_cogs',
        // Hours
        'labor_hours',
        'cleanup_hours',
        'total_hours',
        // Profit snapshot
        'projected_gross_profit',
        'projected_gross_profit_percent',
        'crew_bonus',
        // Actual tracking (legacy)
        'actual_material_cost',
        'actual_labor_cost',
        'actual_subcontractor_cost',
        'actual_other_cost',
        'actual_total_cost',
        'actual_notes',
        // Actual tracking (granular)
        'actual_cleanup_cost',
        'actual_sundries_cost',
        'actual_material_tax',
        'actual_labor_tax',
        'actual_addon_cost',
        'actual_items_vendor_cost',
        'actual_warranty_reserve',
        'actual_crew_bonus',
        'actual_allocation_total',
        'journal_entry_id',
        // Payment summary
        'total_invoiced',
        'total_collected',
        'balance_due',
        // Metadata
        'scope_type',
        'settings_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'production_mgmt_labor' => 'decimal:2',
            'subcontractor_cost' => 'decimal:2',
            'wc_insurance_burden' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'sales_commission' => 'decimal:2',
            'allocation_total' => 'decimal:2',
            'is_manual' => 'boolean',
            'status' => 'string',
            'year' => 'integer',
            // COGS decomposition
            'labor_cost' => 'decimal:2',
            'cleanup_cost' => 'decimal:2',
            'sundries_cost' => 'decimal:2',
            'material_tax' => 'decimal:2',
            'labor_tax' => 'decimal:2',
            'addon_cost' => 'decimal:2',
            'items_vendor_cost' => 'decimal:2',
            'warranty_reserve' => 'decimal:2',
            'total_cogs' => 'decimal:2',
            // Hours
            'labor_hours' => 'decimal:2',
            'cleanup_hours' => 'decimal:2',
            'total_hours' => 'decimal:2',
            // Profit snapshot
            'projected_gross_profit' => 'decimal:2',
            'projected_gross_profit_percent' => 'decimal:2',
            'crew_bonus' => 'decimal:2',
            // Actual tracking (legacy)
            'actual_material_cost' => 'decimal:2',
            'actual_labor_cost' => 'decimal:2',
            'actual_subcontractor_cost' => 'decimal:2',
            'actual_other_cost' => 'decimal:2',
            'actual_total_cost' => 'decimal:2',
            // Actual tracking (granular)
            'actual_cleanup_cost' => 'decimal:2',
            'actual_sundries_cost' => 'decimal:2',
            'actual_material_tax' => 'decimal:2',
            'actual_labor_tax' => 'decimal:2',
            'actual_addon_cost' => 'decimal:2',
            'actual_items_vendor_cost' => 'decimal:2',
            'actual_warranty_reserve' => 'decimal:2',
            'actual_crew_bonus' => 'decimal:2',
            'actual_allocation_total' => 'decimal:2',
            // Payment summary
            'total_invoiced' => 'decimal:2',
            'total_collected' => 'decimal:2',
            'balance_due' => 'decimal:2',
            // Metadata
            'settings_snapshot' => 'array',
        ];
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Get the company setting this card belongs to.
     */
    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    /**
     * Get the job this card is associated with (nullable).
     */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Get the estimate this card is associated with (nullable).
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the allocations for this cost card.
     */
    public function cardAllocations(): HasMany
    {
        return $this->hasMany(JobCostCardAllocation::class);
    }

    /**
     * Get the journal entry linked to this card's actuals.
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    // ─── Legacy computed attributes (unchanged for backwards compatibility) ───

    /**
     * Get the total cost (sum of all legacy cost columns).
     */
    public function getTotalCostAttribute(): float
    {
        return (float) $this->production_mgmt_labor
            + (float) $this->subcontractor_cost
            + (float) $this->wc_insurance_burden
            + (float) $this->material_cost
            + (float) $this->sales_commission;
    }

    /**
     * Get the gross profit (price minus total cost).
     */
    public function getGrossProfitAttribute(): float
    {
        return (float) $this->price - $this->total_cost;
    }

    /**
     * Get the gross profit percentage.
     */
    public function getGrossProfitPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round($this->gross_profit / (float) $this->price * 100, 1);
    }

    /**
     * Get the production management labor percentage of price.
     */
    public function getProductionMgmtPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round((float) $this->production_mgmt_labor / (float) $this->price * 100, 1);
    }

    /**
     * Get the subcontractor cost percentage of price.
     */
    public function getSubcontractorPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round((float) $this->subcontractor_cost / (float) $this->price * 100, 1);
    }

    /**
     * Get the WC & insurance burden percentage of price.
     */
    public function getWcInsurancePercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round((float) $this->wc_insurance_burden / (float) $this->price * 100, 1);
    }

    /**
     * Get the material cost percentage of price.
     */
    public function getMaterialPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round((float) $this->material_cost / (float) $this->price * 100, 1);
    }

    /**
     * Get the sales commission percentage of price.
     */
    public function getCommissionPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round((float) $this->sales_commission / (float) $this->price * 100, 1);
    }

    /**
     * Get the net profit (gross profit minus allocation total).
     */
    public function getNetProfitAttribute(): float
    {
        return $this->gross_profit - (float) $this->allocation_total;
    }

    /**
     * Get the net profit percentage.
     */
    public function getNetProfitPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round($this->net_profit / (float) $this->price * 100, 1);
    }

    /**
     * Get the allocation total percentage of price.
     */
    public function getAllocationPercentAttribute(): float
    {
        if ((float) $this->price <= 0) {
            return 0.0;
        }

        return round((float) $this->allocation_total / (float) $this->price * 100, 1);
    }

    // ─── New computed attributes ───

    /**
     * Whether actual costs have been entered (checks both legacy and granular fields).
     */
    public function getHasActualsAttribute(): bool
    {
        return $this->actual_material_cost !== null
            || $this->actual_labor_cost !== null
            || $this->actual_subcontractor_cost !== null
            || $this->actual_other_cost !== null
            || $this->actual_cleanup_cost !== null
            || $this->actual_sundries_cost !== null
            || $this->actual_material_tax !== null
            || $this->actual_labor_tax !== null
            || $this->actual_addon_cost !== null
            || $this->actual_items_vendor_cost !== null
            || $this->actual_warranty_reserve !== null
            || $this->actual_crew_bonus !== null;
    }

    /**
     * Variance between projected total COGS and actual total cost.
     * Positive = under budget, negative = over budget.
     */
    public function getCostVarianceAttribute(): ?float
    {
        if (! $this->has_actuals || $this->actual_total_cost === null) {
            return null;
        }

        return round((float) $this->total_cogs - (float) $this->actual_total_cost, 2);
    }

    /**
     * Variance percentage (positive = under budget).
     */
    public function getCostVariancePercentAttribute(): ?float
    {
        if ($this->cost_variance === null || (float) $this->total_cogs <= 0) {
            return null;
        }

        return round($this->cost_variance / (float) $this->total_cogs * 100, 1);
    }

    /**
     * Percentage of invoiced amount that has been collected.
     */
    public function getCollectionPercentAttribute(): float
    {
        if ((float) $this->total_invoiced <= 0) {
            return 0.0;
        }

        return round((float) $this->total_collected / (float) $this->total_invoiced * 100, 1);
    }

    /**
     * Whether the balance due is zero or negative (fully paid).
     */
    public function getIsFullyPaidAttribute(): bool
    {
        return (float) $this->total_invoiced > 0 && (float) $this->balance_due <= 0;
    }

    /**
     * Gross profit using actuals if available, otherwise projected.
     */
    public function getEffectiveGrossProfitAttribute(): float
    {
        if ($this->has_actuals && $this->actual_total_cost !== null) {
            return (float) $this->price - (float) $this->actual_total_cost;
        }

        return (float) $this->price - (float) $this->total_cogs;
    }

    // ─── Granular actual computed attributes ───

    /**
     * Sum of all granular actual COGS line items.
     */
    public function getActualCogsAttribute(): float
    {
        return round(
            (float) ($this->actual_labor_cost ?? 0)
            + (float) ($this->actual_material_cost ?? 0)
            + (float) ($this->actual_cleanup_cost ?? 0)
            + (float) ($this->actual_sundries_cost ?? 0)
            + (float) ($this->actual_material_tax ?? 0)
            + (float) ($this->actual_labor_tax ?? 0)
            + (float) ($this->actual_addon_cost ?? 0)
            + (float) ($this->actual_items_vendor_cost ?? 0)
            + (float) ($this->actual_warranty_reserve ?? 0)
            + (float) ($this->actual_subcontractor_cost ?? 0)
            + (float) ($this->actual_other_cost ?? 0),
            2
        );
    }

    /**
     * Actual gross profit: price minus actual COGS.
     */
    public function getActualGrossProfitAttribute(): float
    {
        return round((float) $this->price - $this->actual_cogs, 2);
    }

    /**
     * Actual net profit: actual gross profit minus actual allocation total.
     */
    public function getActualNetProfitAttribute(): float
    {
        return round($this->actual_gross_profit - (float) ($this->actual_allocation_total ?? 0), 2);
    }

    /**
     * All granular actual field names (for iteration).
     *
     * @return array<string>
     */
    public static function granularActualFields(): array
    {
        return [
            'actual_labor_cost',
            'actual_material_cost',
            'actual_cleanup_cost',
            'actual_sundries_cost',
            'actual_material_tax',
            'actual_labor_tax',
            'actual_addon_cost',
            'actual_items_vendor_cost',
            'actual_warranty_reserve',
            'actual_subcontractor_cost',
            'actual_other_cost',
            'actual_crew_bonus',
        ];
    }
}
