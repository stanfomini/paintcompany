<?php

namespace Database\Factories;

use App\Models\Corporation;
use App\Models\CorporationOfficer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CorporationOfficer>
 */
class CorporationOfficerFactory extends Factory
{
    protected $model = CorporationOfficer::class;

    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'name' => fake()->name(),
            'title' => fake()->randomElement(array_keys(CorporationOfficer::TITLES)),
            'sort_order' => 0,
        ];
    }

    public function president(): static
    {
        return $this->state(fn () => [
            'title' => 'President',
        ]);
    }

    public function secretary(): static
    {
        return $this->state(fn () => [
            'title' => 'Secretary',
        ]);
    }
}
