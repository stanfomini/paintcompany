<?php

namespace Database\Factories;

use App\Models\RoadmapPhase;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoadmapCategory>
 */
class RoadmapCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Marketing',
            'Estimating',
            'Sales',
            'Production',
            'Profit',
            'Admin',
            'Time / Efficiency',
            'Commercial Painting',
            'Project Manager',
            'Sales Rep',
            'Office Manager',
        ]);

        return [
            'roadmap_phase_id' => RoadmapPhase::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    public function forPhase(RoadmapPhase $phase): static
    {
        return $this->state(fn (array $attributes) => [
            'roadmap_phase_id' => $phase->id,
        ]);
    }
}
