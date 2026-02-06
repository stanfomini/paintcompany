<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstimateAreaAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_area_id',
        'nested_area_template_id',
        'quantity',
        'unit_price',
        'total_price',
        'labor_hours',
        'material_cost',
        'labor_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'labor_hours' => 'decimal:2',
            'material_cost' => 'decimal:2',
            'labor_cost' => 'decimal:2',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function booted(): void
    {
        static::saving(function (EstimateAreaAddon $addon) {
            // Auto-calculate total_price = unit_price * quantity
            $addon->total_price = (float) $addon->unit_price * (int) $addon->quantity;

            // Auto-populate labor tracking from template if not explicitly set
            $addon->populateLaborFromTemplate();
        });
    }

    /**
     * Populate labor_hours, material_cost, and labor_cost from the template.
     *
     * This only runs if the values aren't already set.
     */
    public function populateLaborFromTemplate(): void
    {
        $template = $this->nestedAreaTemplate;

        if (! $template) {
            return;
        }

        $quantity = (int) $this->quantity;

        // Populate labor_hours from template.estimated_hours × quantity
        if ($this->labor_hours === null && $template->estimated_hours !== null) {
            $this->labor_hours = (float) $template->estimated_hours * $quantity;
        }

        // Populate material_cost from template.material_cost × quantity
        if ($this->material_cost === null && $template->material_cost !== null) {
            $this->material_cost = (float) $template->material_cost * $quantity;
        }

        // Calculate labor_cost = labor_hours × effective hourly rate
        if ($this->labor_cost === null && $this->labor_hours !== null && $this->labor_hours > 0) {
            $this->labor_cost = $this->calculateLaborCost();
        }
    }

    /**
     * Calculate labor cost using effective hourly rate based on scope.
     */
    protected function calculateLaborCost(): float
    {
        $laborHours = (float) $this->labor_hours;

        if ($laborHours <= 0) {
            return 0.0;
        }

        // Get the scope from the parent area
        $scope = $this->estimateArea?->scope_type ?? 'interior';

        // Get effective hourly rate (base wage × payroll burden)
        $settings = CompanySetting::getInstance();
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scope);

        return $laborHours * $effectiveRate;
    }

    /**
     * Get the effective hourly rate used for this addon.
     */
    public function getEffectiveHourlyRateAttribute(): float
    {
        $scope = $this->estimateArea?->scope_type ?? 'interior';
        $settings = CompanySetting::getInstance();

        return $settings->getEffectiveHourlyRateForScope($scope);
    }

    /**
     * Get the labor profit (total_price - labor_cost - material_cost).
     */
    public function getLaborProfitAttribute(): float
    {
        $totalPrice = (float) $this->total_price;
        $laborCost = (float) ($this->labor_cost ?? 0);
        $materialCost = (float) ($this->material_cost ?? 0);

        return $totalPrice - $laborCost - $materialCost;
    }

    /**
     * Get the estimate area this addon belongs to.
     */
    public function estimateArea(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class);
    }

    /**
     * Get the nested area template for this addon.
     */
    public function nestedAreaTemplate(): BelongsTo
    {
        return $this->belongsTo(NestedAreaTemplate::class);
    }
}
