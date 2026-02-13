<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'created_by' => User::factory()->internal(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'notes' => fake()->optional(0.3)->paragraph(),
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the customer is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the customer is archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'archived',
        ]);
    }

    /**
     * Indicate that the customer was referred by an agent.
     */
    public function referredByAgent(?User $agent = null): static
    {
        return $this->state(fn (array $attributes) => [
            'referred_by' => $agent?->id ?? User::factory()->agent(),
        ]);
    }

    /**
     * Indicate that the customer has portal access.
     */
    public function withPortalAccess(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => User::factory()->customer(),
        ]);
    }

    /**
     * Create a customer with specific creator.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    /**
     * Assign the customer to a specific internal user.
     */
    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
        ]);
    }

    /**
     * Create a customer with minimal information.
     */
    public function minimal(): static
    {
        return $this->state(fn (array $attributes) => [
            'email' => null,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'notes' => null,
        ]);
    }
}
