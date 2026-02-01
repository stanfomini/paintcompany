<?php

namespace Database\Factories;

use App\Models\AgentListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ListingPhoto>
 */
class ListingPhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => AgentListing::factory(),
            'uploaded_by' => User::factory(),
            'path' => 'listing-photos/'.fake()->uuid().'.jpg',
            'filename' => fake()->word().'.jpg',
            'caption' => fake()->optional()->sentence(),
            'category' => fake()->randomElement(['before', 'after', 'general']),
            'sort_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * Indicate this is a before photo.
     */
    public function before(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'before',
        ]);
    }

    /**
     * Indicate this is an after photo.
     */
    public function after(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'after',
        ]);
    }

    /**
     * Indicate this is a general photo.
     */
    public function general(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => 'general',
        ]);
    }
}
