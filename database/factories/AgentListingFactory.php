<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AgentListing>
 */
class AgentListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agent_id' => User::factory()->agent(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'status' => 'active',
            'listing_price' => fake()->randomFloat(2, 150000, 1500000),
            'description' => fake()->optional(0.7)->paragraph(),
            'notes' => fake()->optional(0.3)->paragraph(),
            'open_house_date' => fake()->optional(0.5)->dateTimeBetween('now', '+2 weeks'),
        ];
    }

    /**
     * Set as active listing.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    /**
     * Set as pending listing.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    /**
     * Set as sold listing.
     */
    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sold',
        ]);
    }

    /**
     * Set as inactive listing.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Set with upcoming open house.
     */
    public function withOpenHouse(): static
    {
        return $this->state(fn (array $attributes) => [
            'open_house_date' => fake()->dateTimeBetween('+1 day', '+2 weeks'),
        ]);
    }

    /**
     * Associate with a specific agent.
     */
    public function forAgent(User $agent): static
    {
        return $this->state(fn (array $attributes) => [
            'agent_id' => $agent->id,
        ]);
    }
}
