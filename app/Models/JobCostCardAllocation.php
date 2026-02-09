<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobCostCardAllocation extends Model
{
    /** @use HasFactory<\Database\Factories\JobCostCardAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'job_cost_card_id',
        'company_allocation_id',
        'name',
        'slug',
        'type',
        'value',
        'amount',
        'actual_amount',
        'conditions_snapshot',
        'applied_tier',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:4',
            'amount' => 'decimal:2',
            'actual_amount' => 'decimal:2',
            'conditions_snapshot' => 'array',
        ];
    }

    /**
     * Variance: projected minus actual (positive = under budget).
     */
    public function getVarianceAttribute(): ?float
    {
        if ($this->actual_amount === null) {
            return null;
        }

        return round((float) $this->amount - (float) $this->actual_amount, 2);
    }

    /**
     * Get the job cost card this allocation belongs to.
     */
    public function jobCostCard(): BelongsTo
    {
        return $this->belongsTo(JobCostCard::class);
    }

    /**
     * Get the company allocation this was derived from.
     */
    public function companyAllocation(): BelongsTo
    {
        return $this->belongsTo(CompanyAllocation::class);
    }
}
