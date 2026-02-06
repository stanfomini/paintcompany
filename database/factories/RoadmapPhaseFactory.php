<?php

namespace Database\Factories;

use App\Models\RoadmapPhase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoadmapPhase>
 */
class RoadmapPhaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Fundamentals',
            'Phase 1: Foundation',
            'Phase 2: Team Building',
            'Phase 3: Shared Future',
            'Phase 4: Scale',
            'Phase 5: Legacy',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_active' => true,
            'status' => RoadmapPhase::STATUS_NOT_STARTED,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function notStarted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapPhase::STATUS_NOT_STARTED,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapPhase::STATUS_IN_PROGRESS,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapPhase::STATUS_COMPLETED,
        ]);
    }
}
