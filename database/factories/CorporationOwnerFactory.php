<?php

namespace Database\Factories;

use App\Models\Corporation;
use App\Models\CorporationOwner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorporationOwner>
 */
class CorporationOwnerFactory extends Factory
{
    protected $model = CorporationOwner::class;

    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'name' => fake()->name(),
            'percentage' => 50.00,
            'role' => 'Member',
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'sort_order' => 0,
        ];
    }

    public function managingMember(): static
    {
        return $this->state(fn () => [
            'role' => 'Managing Member',
        ]);
    }

    public function president(): static
    {
        return $this->state(fn () => [
            'role' => 'President',
        ]);
    }
}
