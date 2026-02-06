<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OverheadEntry extends Model
{
    /** @use HasFactory<\Database\Factories\OverheadEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'budget_category_id',
        'year',
        'month',
        'amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'decimal:2',
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
