<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token' => Str::random(64),
            'invited_user_type' => fake()->randomElement(['internal', 'agent', 'customer']),
            'inviter_id' => User::factory()->internal(),
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    /**
     * Indicate that the invitation is for an internal user.
     */
    public function forInternal(): static
    {
        return $this->state(fn (array $attributes) => [
            'invited_user_type' => 'internal',
        ]);
    }

    /**
     * Indicate that the invitation is for an agent.
     */
    public function forAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'invited_user_type' => 'agent',
        ]);
    }

    /**
     * Indicate that the invitation is for a customer.
     */
    public function forCustomer(): static
    {
        return $this->state(fn (array $attributes) => [
            'invited_user_type' => 'customer',
        ]);
    }

    /**
     * Indicate that the invitation has been accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'accepted_at' => now(),
        ]);
    }

    /**
     * Indicate that the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Create invitation from an agent inviter.
     */
    public function fromAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'inviter_id' => User::factory()->agent(),
            'invited_user_type' => 'customer',
        ]);
    }
}
