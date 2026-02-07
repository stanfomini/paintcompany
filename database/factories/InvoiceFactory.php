<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 500, 25000);
        $taxRate = 0;
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $taxAmount;

        return [
            'company_setting_id' => fn () => CompanySetting::getInstance()->id,
            'customer_id' => Customer::factory(),
            'invoice_number' => 'INV-'.now()->year.'-'.str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'type' => 'custom',
            'status' => 'draft',
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
            'amount_paid' => 0,
            'balance_due' => $total,
            'due_date' => now()->addDays(30),
            'notes' => fake()->optional(0.3)->sentence(),
        ];
    }

    public function deposit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'deposit',
        ]);
    }

    public function final(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'final',
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'amount_paid' => $attributes['total'] ?? 1000,
            'balance_due' => 0,
            'paid_at' => now(),
        ]);
    }

    public function void(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'void',
        ]);
    }

    public function forEstimate(\App\Models\Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }

    public function forJob(\App\Models\Job $job): static
    {
        return $this->state(fn (array $attributes) => [
            'job_id' => $job->id,
        ]);
    }
}
