<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCategory extends Model
{
    /** @use HasFactory<\Database\Factories\BudgetCategoryFactory> */
    use HasFactory;

    public const SECTION_REVENUE = 'revenue';

    public const SECTION_VARIABLE_EXPENSE = 'variable_expense';

    public const SECTION_VEHICLE_EXPENSE = 'vehicle_expense';

    public const SECTION_BUSINESS_EXPENSE = 'business_expense';

    public const SECTION_MARKETING_EXPENSE = 'marketing_expense';

    public const SECTIONS = [
        self::SECTION_REVENUE,
        self::SECTION_VARIABLE_EXPENSE,
        self::SECTION_VEHICLE_EXPENSE,
        self::SECTION_BUSINESS_EXPENSE,
        self::SECTION_MARKETING_EXPENSE,
    ];

    public const SECTION_LABELS = [
        self::SECTION_REVENUE => 'Revenue',
        self::SECTION_VARIABLE_EXPENSE => 'Variable Expenses',
        self::SECTION_VEHICLE_EXPENSE => 'Vehicle Expenses',
        self::SECTION_BUSINESS_EXPENSE => 'Business Expenses',
        self::SECTION_MARKETING_EXPENSE => 'Marketing Expenses',
    ];

    protected $fillable = [
        'company_setting_id',
        'section',
        'name',
        'slug',
        'sort_order',
        'is_system',
        'sync_source',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(BudgetLineItem::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(Vendor::class);
    }

    public function overheadEntries(): HasMany
    {
        return $this->hasMany(OverheadEntry::class);
    }

    public function getSectionLabelAttribute(): string
    {
        return __(self::SECTION_LABELS[$this->section] ?? $this->section);
    }
}
