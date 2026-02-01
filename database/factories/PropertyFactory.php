<?php

namespace Database\Factories;

use App\Models\Customer;
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
}
