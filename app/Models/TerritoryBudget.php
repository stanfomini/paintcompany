<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerritoryBudget extends Model
{
    use HasFactory;

    protected $fillable = [
        'territory_id',
        'period_type',
        'period_start',
        'marketing_budget',
        'commission_budget_pct',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'marketing_budget' => 'decimal:2',
            'commission_budget_pct' => 'decimal:2',
        ];
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(SalesTerritory::class, 'territory_id');
    }
}
