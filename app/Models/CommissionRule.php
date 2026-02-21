<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CommissionRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'commissionable_type',
        'commissionable_id',
        'name',
        'source',
        'commission_type',
        'rate',
        'amount',
        'conditions',
        'auto_trigger',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
            'conditions' => 'array',
            'auto_trigger' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function commissionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForSource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    public function scopeAutoTrigger(Builder $query): Builder
    {
        return $query->where('auto_trigger', true);
    }
}
