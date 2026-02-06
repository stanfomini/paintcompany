<?php

namespace Database\Factories;

use App\Models\EstimateArea;
use App\Models\NestedAreaTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateAreaAddon>
 */
class EstimateAreaAddonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 75, 250);
        $quantity = fake()->numberBetween(1, 3);

        return [
            'estimate_area_id' => EstimateArea::factory(),
            'nested_area_template_id' => NestedAreaTemplate::factory(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ];
    }

    /**
     * Associate with a specific estimate area.
     */
    public function forArea(EstimateArea $area): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_area_id' => $area->id,
        ]);
    }

    /**
     * Use a specific template.
     */
    public function fromTemplate(NestedAreaTemplate $template): static
    {
        return $this->state(fn (array $attributes) => [
            'nested_area_template_id' => $template->id,
            'unit_price' => $template->fixed_price,
            'total_price' => $template->fixed_price * ($attributes['quantity'] ?? 1),
        ]);
    }

    /**
     * Small closet addon.
     */
    public function smallCloset(): static
    {
        return $this->state(fn (array $attributes) => [
            'nested_area_template_id' => NestedAreaTemplate::factory()->smallCloset(),
            'quantity' => 1,
            'unit_price' => 95.00,
            'total_price' => 95.00,
        ]);
    }

    /**
     * With explicit labor tracking values.
     */
    public function withLaborTracking(float $laborHours, float $materialCost, float $laborCost): static
    {
        return $this->state(fn (array $attributes) => [
            'labor_hours' => $laborHours,
            'material_cost' => $materialCost,
            'labor_cost' => $laborCost,
        ]);
    }

    /**
     * Multiple quantity.
     */
    public function quantity(int $quantity): static
    {
        return $this->state(function (array $attributes) use ($quantity) {
            $unitPrice = $attributes['unit_price'] ?? 100.00;

            return [
                'quantity' => $quantity,
                'total_price' => $unitPrice * $quantity,
            ];
        });
    }
}
