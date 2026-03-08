<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomerDuplicateFlag>
 */
class CustomerDuplicateFlagFactory extends Factory
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
            'duplicate_customer_id' => Customer::factory(),
            'duplicate_user_id' => null,
            'match_type' => fake()->randomElement(['email', 'phone', 'name']),
            'status' => 'pending',
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    /**
     * Indicate the flag has been dismissed.
     */
    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dismissed',
            'resolved_by' => User::factory()->internal(),
            'resolved_at' => now(),
        ]);
    }

    /**
     * Indicate the flag has been merged.
     */
    public function merged(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'merged',
            'resolved_by' => User::factory()->internal(),
            'resolved_at' => now(),
        ]);
    }

    /**
     * Indicate this is a staff/user match.
     */
    public function staffMatch(int $userId): static
    {
        return $this->state(fn (array $attributes) => [
            'duplicate_customer_id' => null,
            'duplicate_user_id' => $userId,
        ]);
    }
}
