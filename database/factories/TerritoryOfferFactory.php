<?php

namespace Database\Factories;

use App\Models\SalesTerritory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TerritoryOffer>
 */
class TerritoryOfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'territory_id' => SalesTerritory::factory(),
            'work_date' => fake()->dateTimeBetween('now', '+2 weeks'),
            'status' => 'pending',
            'offered_at' => now(),
            'expires_at' => now()->addHours(2),
            'created_by' => User::factory()->internal(),
        ];
    }

    /**
     * Set the offer as pending (default).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'expires_at' => now()->addHours(2),
            'claimed_at' => null,
            'claimed_by' => null,
        ]);
    }

    /**
     * Set the offer as claimed.
     */
    public function claimed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'claimed',
            'claimed_at' => now(),
            'claimed_by' => User::factory()->internal(),
        ]);
    }

    /**
     * Set the offer as expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'offered_at' => now()->subHours(3),
            'expires_at' => now()->subHour(),
            'claimed_at' => null,
            'claimed_by' => null,
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
     * Set the work date.
     */
    public function forDate(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'work_date' => $date,
        ]);
    }
}
