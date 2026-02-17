<?php

namespace Database\Factories;

use App\Models\TrainingPath;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingPath>
 */
class TrainingPathFactory extends Factory
{
    protected $model = TrainingPath::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->optional(0.7)->sentence(),
            'visibility' => 'all_internal',
            'is_required' => false,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function required(): static
    {
        return $this->state(fn () => [
            'is_required' => true,
        ]);
    }

    public function forEmployees(): static
    {
        return $this->state(fn () => [
            'visibility' => 'employees',
        ]);
    }

    public function forCrews(): static
    {
        return $this->state(fn () => [
            'visibility' => 'crews',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}
