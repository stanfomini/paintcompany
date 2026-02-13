<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateItem;
use App\Models\EstimateTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateTemplateUsage>
 */
class EstimateTemplateUsageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'template_id' => EstimateTemplate::factory(),
            'quantity' => fake()->randomFloat(2, 50, 500),
            'coats' => fake()->randomElement([1, 2, 3]),
            'calculated_price' => fake()->randomFloat(2, 100, 5000),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }

    /**
     * Associate with a specific template.
     */
    public function forTemplate(EstimateTemplate $template): static
    {
        return $this->state(fn (array $attributes) => [
            'template_id' => $template->id,
        ]);
    }

    /**
     * Set the resultable to an EstimateItem.
     */
    public function withEstimateItemResult(?EstimateItem $item = null): static
    {
        return $this->state(fn (array $attributes) => [
            'resultable_type' => EstimateItem::class,
            'resultable_id' => $item?->id ?? EstimateItem::factory(),
        ]);
    }

    /**
     * Set the resultable to an EstimateArea.
     */
    public function withEstimateAreaResult(?EstimateArea $area = null): static
    {
        return $this->state(fn (array $attributes) => [
            'resultable_type' => EstimateArea::class,
            'resultable_id' => $area?->id ?? EstimateArea::factory(),
        ]);
    }

    /**
     * Create a fixed-price usage (no template_id, resultable is a fixed-price EstimateArea).
     */
    public function fixedPrice(float $price = 150.00): static
    {
        return $this->state(fn (array $attributes) => [
            'template_id' => null,
            'quantity' => 1,
            'coats' => 1,
            'calculated_price' => $price,
        ]);
    }
}
