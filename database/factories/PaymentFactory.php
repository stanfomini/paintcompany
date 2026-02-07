<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'invoice_id' => fn () => Invoice::factory()->create()->id,
            'company_setting_id' => fn () => CompanySetting::getInstance()->id,
            'amount' => fake()->randomFloat(2, 100, 10000),
            'method' => fake()->randomElement(['stripe', 'check', 'cash', 'ach', 'other']),
            'status' => 'completed',
            'received_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function stripe(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'stripe',
            'stripe_payment_intent_id' => 'pi_'.fake()->uuid(),
        ]);
    }

    public function check(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'check',
            'reference_number' => (string) fake()->numberBetween(1000, 9999),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => 'cash',
        ]);
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn (array $attributes) => [
            'invoice_id' => $invoice->id,
        ]);
    }
}
