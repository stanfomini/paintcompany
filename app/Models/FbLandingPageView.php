<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FbLandingPageView extends Model
{
    use HasFactory;

    protected $fillable = [
        'fb_landing_page_offer_id',
        'variant',
        'visitor_id',
        'ip_address',
        'user_agent',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'fbclid',
        'converted',
        'converted_at',
        'customer_id',
        'deal_id',
    ];

    protected function casts(): array
    {
        return [
            'converted' => 'boolean',
            'converted_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(FbLandingPageOffer::class, 'fb_landing_page_offer_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Mark this view as converted with customer and deal linkage.
     */
    public function markConverted(Customer $customer, Deal $deal): void
    {
        $this->update([
            'converted' => true,
            'converted_at' => now(),
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
        ]);
    }

    public function scopeForVariant(Builder $query, string $variant): Builder
    {
        return $query->where('variant', $variant);
    }

    public function scopeConverted(Builder $query): Builder
    {
        return $query->where('converted', true);
    }
}
