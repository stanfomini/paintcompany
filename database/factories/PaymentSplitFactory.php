<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PaymentSplit>
 */
class PaymentSplitFactory extends Factory
{
    protected $model = PaymentSplit::class;

    public function definition(): array
    {
        return [
            'payment_id' => fn () => Payment::factory()->create()->id,
            'destination_name' => fake()->randomElement(['Operating', 'Owner Pay', 'Crew Pay', 'Tax Reserve']),
            'destination_type' => fake()->randomElement(['stripe_connect', 'increase_ach']),
            'destination_account_id' => 'acct_'.fake()->uuid(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'percentage' => fake()->randomFloat(2, 10, 50),
            'status' => 'pending',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'transfer_id' => 'tr_'.fake()->uuid(),
            'executed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'error_message' => 'Transfer failed: insufficient funds',
        ]);
    }

    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => 'stripe_connect',
            'destination_account_id' => 'acct_'.fake()->uuid(),
        ]);
    }

    public function increase(): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => 'increase_ach',
            'destination_account_id' => 'account_'.fake()->uuid(),
        ]);
    }

    public function forPayment(Payment $payment): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_id' => $payment->id,
        ]);
    }
}
