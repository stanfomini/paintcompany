<?php

namespace Database\Factories;

use App\Models\JobListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobListing>
 */
class JobListingFactory extends Factory
{
    protected $model = JobListing::class;

    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Painter', 'Lead Painter', 'Painting Foreman', 'Color Consultant', 'Estimator', 'Project Manager']),
            'description' => fake()->paragraphs(3, true),
            'requirements' => fake()->optional(0.7)->paragraphs(2, true),
            'location' => fake()->city().', '.fake()->stateAbbr(),
            'pay_range_min' => fake()->randomFloat(2, 15, 25),
            'pay_range_max' => fake()->randomFloat(2, 25, 45),
            'employment_type' => 'full_time',
            'status' => 'active',
            'positions_available' => fake()->numberBetween(1, 5),
            'positions_filled' => 0,
            'published_at' => now(),
            'created_by' => User::factory()->internal(),
        ];
    }

    /**
     * Listing in draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    /**
     * Listing that has been closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'closed',
            'closed_at' => now(),
        ]);
    }

    /**
     * Listing with a specific number of positions.
     */
    public function withPositions(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'positions_available' => $count,
        ]);
    }
}
