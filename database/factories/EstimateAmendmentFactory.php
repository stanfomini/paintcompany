<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateAmendment>
 */
class EstimateAmendmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $previousTotal = fake()->randomFloat(2, 1000, 10000);
        $change = fake()->randomFloat(2, -500, 500);
        $newTotal = $previousTotal + $change;

        return [
            'estimate_id' => Estimate::factory()->sent(),
            'amended_by' => User::factory()->internal(),
            'amendment_number' => 1,
            'changes' => [
                [
                    'category' => 'surface',
                    'action' => 'modified',
                    'label' => 'Living Room - Walls',
                    'field' => 'Coats',
                    'old_value' => '2',
                    'new_value' => '3',
                ],
            ],
            'previous_total' => $previousTotal,
            'new_total' => $newTotal,
            'customer_acknowledged_at' => null,
            'customer_initials' => null,
            'customer_ip_address' => null,
        ];
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }

    /**
     * Set the amendment as pending (not acknowledged).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_acknowledged_at' => null,
            'customer_initials' => null,
            'customer_ip_address' => null,
        ]);
    }

    /**
     * Set the amendment as acknowledged by the customer.
     */
    public function acknowledged(): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_acknowledged_at' => now(),
            'customer_initials' => strtoupper(fake()->lexify('??')),
            'customer_ip_address' => fake()->ipv4(),
        ]);
    }
}
