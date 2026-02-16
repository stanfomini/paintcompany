<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LifeCommitment extends Model
{
    /** @use HasFactory<\Database\Factories\LifeCommitmentFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'area',
        'commitment',
        'action_type',
        'sort_order',
        'is_active',
        'completed_at',
        'start_date',
        'recurrence_days',
        'recurrence_time',
        'recurrence_end_time',
        'recurrence_day_of_month',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'completed_at' => 'datetime',
            'start_date' => 'date',
            'recurrence_days' => 'array',
            'recurrence_day_of_month' => 'integer',
        ];
    }

    /**
     * Get the company setting this commitment belongs to.
     */
    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    /**
     * Scope to order by sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('created_at');
    }

    /**
     * Scope to only active commitments.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to only incomplete commitments (not yet completed).
     */
    public function scopeIncomplete(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
}
