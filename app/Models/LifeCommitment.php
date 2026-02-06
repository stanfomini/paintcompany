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
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
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
}
