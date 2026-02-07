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
     * Get the total cost (sum of all cost columns).
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
}
