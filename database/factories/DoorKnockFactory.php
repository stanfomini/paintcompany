<?php

namespace Database\Factories;

use App\Models\SalesTerritory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DoorKnock>
 */
class DoorKnockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->internal(),
            'territory_id' => SalesTerritory::factory(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => fake()->stateAbbr(),
            'zip' => fake()->postcode(),
            'lat' => fake()->latitude(30, 45),
            'lng' => fake()->longitude(-120, -75),
            'status' => 'not_home',
            'customer_name' => fake()->optional(0.6)->name(),
            'phone' => fake()->optional(0.4)->phoneNumber(),
            'email' => fake()->optional(0.3)->safeEmail(),
            'notes' => fake()->optional(0.3)->sentence(),
            'knocked_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ];
    }

    /**
     * Set status to not home.
     */
    public function notHome(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'not_home',
        ]);
    }

    /**
     * Set status to interested.
     */
    public function interested(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'interested',
            'customer_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
        ]);
    }

    /**
     * Set status to not interested.
     */
    public function notInterested(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'not_interested',
        ]);
    }

    /**
     * Set status to appointment set.
     */
    public function appointmentSet(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'appointment_set',
            'customer_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
        ]);
    }

    /**
     * Set status to sold.
     */
    public function sold(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sold',
            'customer_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
        ]);
    }

    /**
     * Set status to come back.
     */
    public function comeBack(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'come_back',
            'notes' => fake()->sentence(),
        ]);
    }

    /**
     * Associate with a specific territory.
     */
    public function forTerritory(SalesTerritory $territory): static
    {
        return $this->state(fn (array $attributes) => [
            'territory_id' => $territory->id,
        ]);
    }

    /**
     * Associate with a specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }
}
