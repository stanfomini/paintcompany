<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaTemplateNestedOption extends Model
{
    /** @use HasFactory<\Database\Factories\AreaTemplateNestedOptionFactory> */
    use HasFactory;

    protected $fillable = [
        'parent_template_id',
        'nested_template_id',
        'nested_area_template_id',
        'name',
        'is_default_included',
        'max_quantity',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default_included' => 'boolean',
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the parent template this option belongs to.
     */
    public function parentTemplate(): BelongsTo
    {
        return $this->belongsTo(EstimateTemplate::class, 'parent_template_id');
    }

    /**
     * Get the nested estimate template (if linking to another area template).
     */
    public function nestedTemplate(): BelongsTo
    {
        return $this->belongsTo(EstimateTemplate::class, 'nested_template_id');
    }

    /**
     * Get the nested area template (if linking to a fixed-price nested template).
     */
    public function nestedAreaTemplate(): BelongsTo
    {
        return $this->belongsTo(NestedAreaTemplate::class, 'nested_area_template_id');
    }

    /**
     * Scope to only include default-included options.
     */
    public function scopeDefaultIncluded(Builder $query): Builder
    {
        return $query->where('is_default_included', true);
    }

    /**
     * Scope to order by sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get the price of this nested option.
     * Returns the fixed_price from either the nested template or nested area template.
     */
    public function getPrice(): float
    {
        if ($this->nestedAreaTemplate) {
            return (float) ($this->nestedAreaTemplate->fixed_price ?? 0);
        }

        if ($this->nestedTemplate) {
            return (float) ($this->nestedTemplate->base_price ?? 0);
        }

        return 0.0;
    }

    /**
     * Get the display name for this nested option.
     * Uses the override name if set, otherwise falls back to the nested template name.
     */
    public function getDisplayNameAttribute(): string
    {
        if (! empty($this->name)) {
            return $this->name;
        }

        if ($this->nestedAreaTemplate) {
            return $this->nestedAreaTemplate->name ?? 'Nested Area';
        }

        if ($this->nestedTemplate) {
            return $this->nestedTemplate->name ?? 'Nested Template';
        }

        return 'Unknown';
    }

    /**
     * Check if quantity is limited.
     */
    public function hasQuantityLimit(): bool
    {
        return $this->max_quantity !== null && $this->max_quantity > 0;
    }
}
