<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoiCalculation>
 */
class RoiCalculationFactory extends Factory
{
    public function definition(): array
    {
        $costLow = fake()->randomFloat(2, 3000, 8000);
        $costHigh = $costLow * 1.3;
        $propertyValue = fake()->randomFloat(2, 200000, 800000);

        return [
            'deal_id' => Deal::factory(),
            'agent_id' => User::factory()->agent(),
            'property_value' => $propertyValue,
            'estimated_sqft' => fake()->numberBetween(1000, 4000),
            'bedroom_count' => fake()->numberBetween(2, 5),
            'scope' => fake()->randomElement(['interior', 'exterior', 'both']),
            'intent' => fake()->randomElement(['selling', 'buying']),
            'estimated_cost_low' => $costLow,
            'estimated_cost_high' => $costHigh,
            'estimated_value_increase_low' => $costLow * 1.07,
            'estimated_value_increase_high' => $propertyValue * 0.05,
            'roi_percent_low' => 107.00,
            'roi_percent_high' => 500.00,
            'calculation_inputs' => [
                'effective_rate_interior' => 28.35,
                'effective_rate_exterior' => 36.45,
                'margin_interior' => 50,
                'margin_exterior' => 41,
            ],
        ];
    }

    /**
     * Set the intent to selling.
     */
    public function selling(): static
    {
        return $this->state(fn (array $attributes) => [
            'intent' => 'selling',
        ]);
    }

    /**
     * Set the intent to buying.
     */
    public function buying(): static
    {
        return $this->state(fn (array $attributes) => [
            'intent' => 'buying',
        ]);
    }

    /**
     * Set the scope to interior.
     */
    public function interior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'interior',
        ]);
    }

    /**
     * Set the scope to exterior.
     */
    public function exterior(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'exterior',
        ]);
    }

    /**
     * Set the scope to both.
     */
    public function both(): static
    {
        return $this->state(fn (array $attributes) => [
            'scope' => 'both',
        ]);
    }

    /**
     * Create with a public sharing token.
     */
    public function withPublicToken(): static
    {
        return $this->state(fn (array $attributes) => [
            'public_token' => fake()->regexify('[a-zA-Z0-9]{64}'),
            'public_token_expires_at' => now()->addDays(30),
        ]);
    }
}
