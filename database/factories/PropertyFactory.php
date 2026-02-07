<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->optional(0.5)->words(2, true),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'type' => 'residential',
            'square_footage' => fake()->numberBetween(1000, 5000),
            'year_built' => fake()->numberBetween(1950, 2024),
            'notes' => fake()->optional(0.3)->paragraph(),
        ];
    }

    /**
     * Indicate that the property is residential.
     */
    public function residential(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'residential',
        ]);
    }

    /**
     * Indicate that the property is commercial.
     */
    public function commercial(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'commercial',
            'square_footage' => fake()->numberBetween(5000, 50000),
        ]);
    }

    /**
     * Indicate that the property is industrial.
     */
    public function industrial(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'industrial',
            'square_footage' => fake()->numberBetween(10000, 100000),
        ]);
    }

    /**
     * Associate with a specific customer.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Configure as an agent listing (no customer, has agent).
     */
    public function agentListing(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => null,
            'agent_id' => User::factory()->agent(),
            'source' => 'agent_referral',
            'listing_status' => 'active',
            'listing_price' => fake()->randomFloat(2, 150000, 1500000),
            'description' => fake()->optional(0.7)->paragraph(),
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

    /**
     * Set listing status to active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_status' => 'active',
        ]);
    }

    /**
     * Set listing status to pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_status' => 'pending',
        ]);
    }

    /**
     * Set listing status to sold.
     */
    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'listing_status' => 'sold',
        ]);
    }
}
