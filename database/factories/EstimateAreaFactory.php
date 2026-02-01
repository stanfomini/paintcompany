<?php

namespace Database\Factories;

use App\Models\Estimate;
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
}
