<?php

namespace Database\Factories;

use App\Models\CallGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CallGroup>
 */
class CallGroupFactory extends Factory
{
    protected $model = CallGroup::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Team',
            'description' => fake()->optional()->sentence(),
            'ring_timeout_seconds' => fake()->randomElement([12, 15, 17, 20]),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
