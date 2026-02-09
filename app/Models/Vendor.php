<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vendor extends Model
{
    use HasFactory;

    public const CATEGORY_SUPPLIER = 'supplier';

    public const CATEGORY_SUBCONTRACTOR = 'subcontractor';

    public const CATEGORY_SERVICE = 'service';

    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_SUPPLIER => 'Supplier',
        self::CATEGORY_SUBCONTRACTOR => 'Subcontractor',
        self::CATEGORY_SERVICE => 'Service Provider',
        self::CATEGORY_OTHER => 'Other',
    ];

    protected $fillable = [
        'company_setting_id', 'name', 'contact_name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'zip',
        'category', 'budget_category_id', 'payment_terms_days', 'is_active', 'notes',
        'w9_on_file', 'hold_harmless', 'certificate_of_insurance',
        'insurance_expiration', 'additional_insured', 'workers_comp',
        'workers_comp_expiration', 'rejection_form',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_terms_days' => 'integer',
            'w9_on_file' => 'boolean',
            'hold_harmless' => 'boolean',
            'certificate_of_insurance' => 'boolean',
            'insurance_expiration' => 'date',
            'additional_insured' => 'boolean',
            'workers_comp' => 'boolean',
            'workers_comp_expiration' => 'date',
            'rejection_form' => 'boolean',
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
