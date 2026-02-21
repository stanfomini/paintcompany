<?php

namespace Database\Factories;

use App\Models\ServiceArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ServiceArea>
 */
class ServiceAreaFactory extends Factory
{
    public function definition(): array
    {
        $city = fake()->city();

        return [
            'city' => $city,
            'state' => 'CO',
            'slug' => Str::slug($city).'-co',
            'zip_codes' => [fake()->postcode()],
            'territory_id' => null,
            'meta_title' => null,
            'meta_description' => null,
            'hero_text' => null,
            'specialties_text' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
