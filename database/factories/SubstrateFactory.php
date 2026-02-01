<?php

namespace Database\Factories;

use App\Models\Substrate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Substrate>
 */
class SubstrateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->lexify('SUB-???'),
            'description' => fake()->sentence(),
            'scope_type' => fake()->randomElement([
                Substrate::SCOPE_TYPE_INTERIOR,
                Substrate::SCOPE_TYPE_EXTERIOR,
                Substrate::SCOPE_TYPE_BOTH,
            ]),
            'category' => fake()->randomElement([
                Substrate::CATEGORY_STANDARD,
                Substrate::CATEGORY_WOOD,
                Substrate::CATEGORY_MASONRY,
                Substrate::CATEGORY_METAL,
            ]),
            'requires_prime' => fake()->boolean(30),
            'requires_sand' => fake()->boolean(20),
            'requires_scrape' => fake()->boolean(10),
            'requires_wash' => fake()->boolean(40),
            'prep_time_multiplier' => fake()->randomFloat(2, 1.0, 2.0),
            'paint_time_multiplier' => fake()->randomFloat(2, 1.0, 1.5),
            'material_multiplier' => fake()->randomFloat(2, 1.0, 1.5),
            'default_production_rate' => fake()->numberBetween(150, 400),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    /**
     * Previously painted drywall (most common interior).
     */
    public function previouslyPainted(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Previously Painted Drywall',
            'code' => 'PREV-PAINT',
            'scope_type' => Substrate::SCOPE_TYPE_INTERIOR,
            'category' => Substrate::CATEGORY_STANDARD,
            'requires_prime' => false,
            'requires_sand' => false,
            'requires_scrape' => false,
            'requires_wash' => false,
            'prep_time_multiplier' => 1.00,
            'paint_time_multiplier' => 1.00,
            'material_multiplier' => 1.00,
            'default_production_rate' => 300,
        ]);
    }

    /**
     * New drywall (requires prime).
     */
    public function newDrywall(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'New Drywall',
            'code' => 'NEW-DW',
            'scope_type' => Substrate::SCOPE_TYPE_INTERIOR,
            'category' => Substrate::CATEGORY_STANDARD,
            'requires_prime' => true,
            'requires_sand' => true,
            'requires_scrape' => false,
            'requires_wash' => false,
            'prep_time_multiplier' => 1.30,
            'paint_time_multiplier' => 1.00,
            'material_multiplier' => 1.20,
            'default_production_rate' => 250,
        ]);
    }

    /**
     * Peeling/damaged paint (high prep).
     */
    public function peelingPaint(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Peeling/Damaged Paint',
            'code' => 'PEEL',
            'scope_type' => Substrate::SCOPE_TYPE_BOTH,
            'category' => Substrate::CATEGORY_STANDARD,
            'requires_prime' => true,
            'requires_sand' => true,
            'requires_scrape' => true,
            'requires_wash' => false,
            'prep_time_multiplier' => 2.00,
            'paint_time_multiplier' => 1.10,
            'material_multiplier' => 1.30,
            'default_production_rate' => 150,
        ]);
    }

    /**
     * Exterior wood siding.
     */
    public function woodSiding(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Wood Siding',
            'code' => 'WOOD-SID',
            'scope_type' => Substrate::SCOPE_TYPE_EXTERIOR,
            'category' => Substrate::CATEGORY_WOOD,
            'requires_prime' => false,
            'requires_sand' => false,
            'requires_scrape' => false,
            'requires_wash' => true,
            'prep_time_multiplier' => 1.20,
            'paint_time_multiplier' => 1.10,
            'material_multiplier' => 1.15,
            'default_production_rate' => 200,
        ]);
    }

    /**
     * Exterior stucco.
     */
    public function stucco(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Stucco',
            'code' => 'STUCCO',
            'scope_type' => Substrate::SCOPE_TYPE_EXTERIOR,
            'category' => Substrate::CATEGORY_MASONRY,
            'requires_prime' => false,
            'requires_sand' => false,
            'requires_scrape' => false,
            'requires_wash' => true,
            'prep_time_multiplier' => 1.15,
            'paint_time_multiplier' => 1.20,
            'material_multiplier' => 1.40,
            'default_production_rate' => 180,
        ]);
    }

    /**
     * Interior only scope.
     */
    public function interior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope_type' => Substrate::SCOPE_TYPE_INTERIOR,
        ]);
    }

    /**
     * Exterior only scope.
     */
    public function exterior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope_type' => Substrate::SCOPE_TYPE_EXTERIOR,
        ]);
    }

    /**
     * Inactive substrate.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
