<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\NestedAreaTemplate;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateArea>
 */
class EstimateAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $length = fake()->randomFloat(2, 10, 20);
        $width = fake()->randomFloat(2, 10, 15);
        $height = fake()->randomFloat(2, 8, 10);

        return [
            'estimate_id' => Estimate::factory(),
            'name' => fake()->randomElement([
                'Living Room',
                'Kitchen',
                'Master Bedroom',
                'Bedroom',
                'Bathroom',
                'Dining Room',
                'Family Room',
                'Office',
                'Hallway',
                'Foyer',
                'Laundry Room',
                'Garage',
                'Basement',
                'Bonus Room',
                'Guest Room',
            ]),
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'wall_sqft' => 2 * ($length + $width) * $height,
            'ceiling_sqft' => $length * $width,
            'floor_sqft' => $length * $width,
            'measurement_type' => 'dimensions',
            'wall_count' => 4,
            'notes' => fake()->optional(0.3)->sentence(10),
            'sort_order' => 0,
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
     * Set as a small room (bathroom, closet, etc.)
     */
    public function small(): static
    {
        $length = fake()->randomFloat(2, 6, 10);
        $width = fake()->randomFloat(2, 5, 8);
        $height = fake()->randomFloat(2, 8, 9);

        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Bathroom', 'Powder Room', 'Closet', 'Pantry', 'Laundry Room']),
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'wall_sqft' => 2 * ($length + $width) * $height,
            'ceiling_sqft' => $length * $width,
            'floor_sqft' => $length * $width,
        ]);
    }

    /**
     * Set as a large room (great room, basement, etc.)
     */
    public function large(): static
    {
        $length = fake()->randomFloat(2, 20, 35);
        $width = fake()->randomFloat(2, 15, 25);
        $height = fake()->randomFloat(2, 9, 12);

        return $this->state(fn (array $attributes) => [
            'name' => fake()->randomElement(['Great Room', 'Basement', 'Garage', 'Open Floor Plan', 'Loft']),
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'wall_sqft' => 2 * ($length + $width) * $height,
            'ceiling_sqft' => $length * $width,
            'floor_sqft' => $length * $width,
        ]);
    }

    /**
     * Set measurement type to direct sqft input.
     */
    public function directSqft(float $sqft, ?float $ceilingSqft = null): static
    {
        return $this->state(fn (array $attributes) => [
            'measurement_type' => EstimateArea::MEASUREMENT_SQFT,
            'direct_sqft' => $sqft,
            'direct_ceiling_sqft' => $ceilingSqft,
            'length' => 0,
            'width' => 0,
        ]);
    }

    /**
     * Set measurement type to direct linear feet input.
     */
    public function directLnft(float $lnft, ?float $ceilingSqft = null): static
    {
        return $this->state(fn (array $attributes) => [
            'measurement_type' => EstimateArea::MEASUREMENT_LNFT,
            'direct_lnft' => $lnft,
            'direct_ceiling_sqft' => $ceilingSqft,
            'length' => 0,
            'width' => 0,
        ]);
    }

    /**
     * Set wall count for dimensions mode (1-4).
     */
    public function wallCount(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'wall_count' => max(1, min(4, $count)),
        ]);
    }

    /**
     * Associate with a service category.
     */
    public function withServiceCategory(?ServiceCategory $category = null): static
    {
        return $this->state(fn (array $attributes) => [
            'service_category_id' => $category?->id ?? ServiceCategory::factory(),
        ]);
    }

    /**
     * Set as a fixed-price area (closet, half bath, etc.)
     */
    public function fixedPrice(float $amount = 150.00, ?NestedAreaTemplate $nestedTemplate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => $nestedTemplate?->name ?? fake()->randomElement(['Small Closet', 'Half Bath', 'Pantry']),
            'nested_area_template_id' => $nestedTemplate?->id,
            'is_fixed_price' => true,
            'fixed_price_amount' => $amount,
            'estimated_labor_hours' => $nestedTemplate?->estimated_hours,
            'estimated_material_cost' => $nestedTemplate?->material_cost,
            'length' => 0,
            'width' => 0,
            'height' => 0,
            'wall_sqft' => 0,
            'ceiling_sqft' => 0,
            'floor_sqft' => 0,
        ]);
    }
}
