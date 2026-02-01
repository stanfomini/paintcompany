<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaintBrand>
 */
class PaintBrandFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Paints',
            'code' => fake()->unique()->lexify('???'),
            'logo_path' => null,
            'description' => fake()->sentence(),
            'website_url' => fake()->url(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Sherwin-Williams brand.
     */
    public function sherwinWilliams(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Sherwin-Williams',
            'code' => 'SW',
            'description' => 'Premium professional-grade paints',
            'website_url' => 'https://www.sherwin-williams.com',
            'sort_order' => 1,
        ]);
    }

    /**
     * Benjamin Moore brand.
     */
    public function benjaminMoore(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Benjamin Moore',
            'code' => 'BM',
            'description' => 'High-quality paints with excellent color selection',
            'website_url' => 'https://www.benjaminmoore.com',
            'sort_order' => 2,
        ]);
    }

    /**
     * Behr brand.
     */
    public function behr(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Behr',
            'code' => 'BEHR',
            'description' => 'Quality paints at competitive prices',
            'website_url' => 'https://www.behr.com',
            'sort_order' => 3,
        ]);
    }

    /**
     * PPG brand.
     */
    public function ppg(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'PPG',
            'code' => 'PPG',
            'description' => 'Professional paint products',
            'website_url' => 'https://www.ppg.com',
            'sort_order' => 4,
        ]);
    }

    /**
     * Inactive brand.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
