<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLineItem extends Model
{
    /** @use HasFactory<\Database\Factories\BudgetLineItemFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'budget_category_id',
        'year',
        'prior_year_actual',
        'current_year_plan',
        'current_year_actual',
        'sync_source',
        'synced_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'prior_year_actual' => 'decimal:2',
            'current_year_plan' => 'decimal:2',
            'current_year_actual' => 'decimal:2',
            'synced_at' => 'datetime',
        ];
    }

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }
}
