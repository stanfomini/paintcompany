<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingTrackingEntry extends Model
{
    /** @use HasFactory<\Database\Factories\MarketingTrackingEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'company_setting_id',
        'year',
        'source_name',
        'marketing_expense',
        'leads',
        'estimates',
        'jobs_booked',
        'total_sales',
        'is_manual',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'marketing_expense' => 'decimal:2',
            'total_sales' => 'decimal:2',
            'is_manual' => 'boolean',
            'year' => 'integer',
            'leads' => 'integer',
            'estimates' => 'integer',
            'jobs_booked' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the company setting this entry belongs to.
     */
    public function companySetting(): BelongsTo
    {
        return $this->belongsTo(CompanySetting::class);
    }

    /**
     * Calculate cost per lead (marketing_expense / leads).
     * Returns 0 if there are no leads.
     */
    public function getCostPerLeadAttribute(): float
    {
        if ($this->leads <= 0) {
            return 0;
        }

        return round((float) $this->marketing_expense / $this->leads, 2);
    }

    /**
     * Calculate ROI percentage: (total_sales - marketing_expense) / marketing_expense * 100.
     * Returns 0 if there is no marketing expense.
     */
    public function getRoiPercentAttribute(): float
    {
        if ((float) $this->marketing_expense <= 0) {
            return 0;
        }

        return round(((float) $this->total_sales - (float) $this->marketing_expense) / (float) $this->marketing_expense * 100, 1);
    }

    /**
     * Default marketing sources for a new year.
     *
     * @return array<int, string>
     */
    public static function defaultSources(): array
    {
        return [
            'Website & SEO',
            'Branding',
            'Networking & Associations',
            'Homeshows',
            'Client Events',
            'Google AdWords',
            'Marketing Wages',
            'Clothing',
            'Business Cards',
            'Signs',
            'Painter Choice',
            'Thumbtack',
            'Craft Jack',
            'Yelp',
            'HomeAdvisor',
            'Houzz',
            'Porch',
            'Facebook',
            'Networx',
            'Vehicles',
            'Direct Mail',
            'Community Marketing',
            'Other Marketing',
            'BBB',
        ];
    }
}
