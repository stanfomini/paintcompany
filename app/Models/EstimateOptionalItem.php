<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateOptionalItem extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateOptionalItemFactory> */
    use HasFactory;

    public const PRICING_FIXED = 'fixed';

    public const PRICING_PERCENT = 'percent';

    protected $fillable = [
        'estimate_id',
        'template_id',
        'name',
        'description',
        'pricing_type',
        'price',
        'percent_of_total',
        'is_selected_by_customer',
        'selected_at',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'percent_of_total' => 'decimal:2',
            'is_selected_by_customer' => 'boolean',
            'selected_at' => 'datetime',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the estimate for this optional item.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the template for this optional item.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EstimateTemplate::class, 'template_id');
    }

    /**
     * Calculate the price based on pricing type.
     *
     * @param  float  $estimateTotal  The estimate total to use for percent calculations
     */
    public function calculatePrice(float $estimateTotal): float
    {
        if ($this->pricing_type === self::PRICING_PERCENT) {
            return $estimateTotal * (float) ($this->percent_of_total ?? 0) / 100;
        }

        return (float) ($this->price ?? 0);
    }

    /**
     * Scope to filter selected optional items.
     */
    public function scopeSelected(Builder $query): Builder
    {
        return $query->where('is_selected_by_customer', true);
    }

    /**
     * Mark this optional item as selected by the customer.
     */
    public function select(): void
    {
        $this->is_selected_by_customer = true;
        $this->selected_at = now();
        $this->save();
    }

    /**
     * Mark this optional item as deselected by the customer.
     */
    public function deselect(): void
    {
        $this->is_selected_by_customer = false;
        $this->selected_at = null;
        $this->save();
    }
}
