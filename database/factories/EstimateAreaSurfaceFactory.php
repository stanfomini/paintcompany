<?php

namespace Database\Factories;

use App\Models\EstimateArea;
use App\Models\ProductionRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateAreaSurface>
 */
class EstimateAreaSurfaceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(2, 50, 500);
        $productionRate = fake()->randomFloat(2, 100, 400);
        $hourlyRate = fake()->randomFloat(2, 45, 75);
        $materialCost = fake()->randomFloat(2, 0.15, 0.50);
        $coats = fake()->randomElement([1, 2, 3]);

        $laborHours = ($quantity / $productionRate) * $coats;
        $laborCost = $laborHours * $hourlyRate;
        $materialTotal = $quantity * $materialCost * $coats;
        $total = $laborCost + $materialTotal;

        return [
            'area_id' => EstimateArea::factory(),
            'production_rate_id' => null,
            'name' => fake()->randomElement([
                'Wall Painting',
                'Ceiling Painting',
                'Trim & Baseboard',
                'Door Painting',
                'Window Frame',
                'Crown Molding',
                'Wainscoting',
            ]),
            'surface_type' => fake()->randomElement(['wall', 'ceiling', 'trim', 'door', 'window']),
            'quantity' => $quantity,
            'unit' => fake()->randomElement(['sqft', 'lnft', 'unit']),
            'coats' => $coats,
            'application_method' => fake()->randomElement(['spray', 'brush_roll']),
            'paint_brand' => fake()->randomElement(['sherwin_williams', 'benjamin_moore', 'ppg']),
            'paint_type' => fake()->randomElement(['duration', 'superpaint', 'aura', 'diamond']),
            'paint_gallons' => null,
            'paint_cost' => null,
            'production_rate_value' => $productionRate,
            'hourly_labor_rate' => $hourlyRate,
            'material_cost_per_unit' => $materialCost,
            'labor_hours' => $laborHours,
            'labor_cost' => $laborCost,
            'material_cost' => $materialTotal,
            'total' => $total,
            'is_included' => true,
            'is_custom' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Associate with a specific area.
     */
    public function forArea(EstimateArea $area): static
    {
        return $this->state(fn (array $attributes) => [
            'area_id' => $area->id,
        ]);
    }

    /**
     * Associate with a specific production rate.
     * Labor costs are calculated using Company Settings effective rate.
     */
    public function forRate(ProductionRate $rate, string $scopeType = 'interior'): static
    {
        return $this->state(function (array $attributes) use ($rate, $scopeType) {
            $quantity = $attributes['quantity'];
            $coats = $attributes['coats'];

            $settings = \App\Models\CompanySetting::getInstance();
            $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);

            $laborHours = ($quantity / $rate->production_rate) * $coats;
            $laborCost = $laborHours * $effectiveRate;
            $materialTotal = $quantity * 0.15 * $coats; // Default material cost fallback
            $total = $laborCost + $materialTotal;

            return [
                'production_rate_id' => $rate->id,
                'name' => $rate->name,
                'surface_type' => $rate->unit_type,
                'unit' => $rate->unit_type,
                'production_rate_value' => $rate->production_rate,
                'hourly_labor_rate' => $effectiveRate, // From Company Settings
                'material_cost_per_unit' => 0.15, // Default fallback
                'labor_hours' => $laborHours,
                'labor_cost' => $laborCost,
                'material_cost' => $materialTotal,
                'total' => $total,
            ];
        });
    }

    /**
     * Set as wall surface.
     */
    public function wall(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Wall Painting',
            'surface_type' => 'wall',
            'unit' => 'sqft',
        ]);
    }

    /**
     * Set as ceiling surface.
     */
    public function ceiling(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Ceiling Painting',
            'surface_type' => 'ceiling',
            'unit' => 'sqft',
        ]);
    }

    /**
     * Set as trim surface.
     */
    public function trim(): static
    {
        $quantity = fake()->randomFloat(2, 20, 100);

        return $this->state(fn (array $attributes) => [
            'name' => 'Trim & Baseboard',
            'surface_type' => 'trim',
            'unit' => 'lnft',
            'quantity' => $quantity,
        ]);
    }

    /**
     * Set as door surface.
     */
    public function door(): static
    {
        $quantity = fake()->numberBetween(1, 8);

        return $this->state(fn (array $attributes) => [
            'name' => 'Door Painting',
            'surface_type' => 'door',
            'unit' => 'unit',
            'quantity' => $quantity,
        ]);
    }

    /**
     * Set as window surface.
     */
    public function window(): static
    {
        $quantity = fake()->numberBetween(1, 6);

        return $this->state(fn (array $attributes) => [
            'name' => 'Window Frame',
            'surface_type' => 'window',
            'unit' => 'unit',
            'quantity' => $quantity,
        ]);
    }

    /**
     * Mark as excluded from estimate.
     */
    public function excluded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_included' => false,
        ]);
    }

    /**
     * Set application method to spray.
     */
    public function spray(): static
    {
        return $this->state(fn (array $attributes) => [
            'application_method' => 'spray',
        ]);
    }

    /**
     * Set application method to brush/roll.
     */
    public function brushRoll(): static
    {
        return $this->state(fn (array $attributes) => [
            'application_method' => 'brush_roll',
        ]);
    }

    /**
     * Set paint brand and type.
     */
    public function withPaint(string $brand, string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'paint_brand' => $brand,
            'paint_type' => $type,
        ]);
    }

    /**
     * Set Sherwin Williams Duration paint.
     */
    public function sherwinWilliamsDuration(): static
    {
        return $this->withPaint('sherwin_williams', 'duration');
    }

    /**
     * Set Benjamin Moore Aura paint.
     */
    public function benjaminMooreAura(): static
    {
        return $this->withPaint('benjamin_moore', 'aura');
    }

    /**
     * Set as a custom surface with manual labor hours and material cost.
     */
    public function custom(float $laborHours, float $materialCost, ?float $hourlyRate = null): static
    {
        $effectiveRate = $hourlyRate ?? 45.00;
        $laborCost = $laborHours * $effectiveRate;

        return $this->state(fn (array $attributes) => [
            'is_custom' => true,
            'production_rate_id' => null,
            'production_rate_value' => 0,
            'labor_hours' => $laborHours,
            'hourly_labor_rate' => $effectiveRate,
            'labor_cost' => $laborCost,
            'material_cost' => $materialCost,
            'total' => $laborCost + $materialCost,
        ]);
    }

    /**
     * Set custom pricing overrides (sale_price and/or crew_price).
     * Must be combined with custom() state since these only apply to is_custom surfaces.
     *
     * @param  float|null  $salePrice  Customer price override for this surface
     * @param  float|null  $crewRate  Hourly rate override for crew ($/hr)
     */
    public function withCustomPricing(?float $salePrice = null, ?float $crewRate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'sale_price' => $salePrice,
            'crew_price' => $crewRate,
        ]);
    }
}
