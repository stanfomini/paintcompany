<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EstimateTemplateUsage extends Model
{
    /** @use HasFactory<\Database\Factories\EstimateTemplateUsageFactory> */
    use HasFactory;

    protected $fillable = [
        'estimate_id',
        'template_id',
        'quantity',
        'coats',
        'calculated_price',
        'resultable_type',
        'resultable_id',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'coats' => 'integer',
            'calculated_price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateTemplateUsage $usage) {
            if ($usage->template) {
                $usage->calculated_price = $usage->template->calculatePrice(
                    (float) $usage->quantity,
                    $usage->coats
                );
            }
        });
    }

    /**
     * Get the estimate for this template usage.
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * Get the template for this usage.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EstimateTemplate::class, 'template_id');
    }

    /**
     * Get the resultable model (EstimateItem, EstimateArea, or EstimateAreaSurface).
     */
    public function resultable(): MorphTo
    {
        return $this->morphTo();
    }
}
