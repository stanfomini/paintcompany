<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Estimate>
 */
class EstimateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 500, 25000);
        $taxRate = fake()->randomElement([0, 6, 7, 8, 10]);
        $taxAmount = $subtotal * ($taxRate / 100);
        $discountAmount = fake()->boolean(30) ? fake()->randomFloat(2, 50, 500) : 0;
        $total = $subtotal + $taxAmount - $discountAmount;

        return [
            'deal_id' => Deal::factory(),
            'created_by' => User::factory()->internal(),
            'estimate_number' => fn () => 'EST-'.date('Y').'-'.str_pad(fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'title' => fake()->sentence(4),
            'description' => fake()->optional(0.5)->paragraph(),
            'status' => 'draft',
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => $total,
            'notes' => fake()->optional(0.3)->paragraph(),
            'terms' => fake()->optional(0.5)->paragraph(),
            'valid_until' => fake()->optional(0.7)->dateTimeBetween('+1 week', '+1 month'),
        ];
    }

    /**
     * Set the estimate status to draft.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'draft',
        ]);
    }

    /**
     * Set the estimate status to sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'sent_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Set the estimate status to accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'sent_at' => fake()->dateTimeBetween('-2 weeks', '-1 week'),
            'accepted_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Set the estimate status to rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'sent_at' => fake()->dateTimeBetween('-2 weeks', '-1 week'),
            'rejected_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Set the estimate status to expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'sent_at' => fake()->dateTimeBetween('-2 months', '-1 month'),
            'valid_until' => fake()->dateTimeBetween('-1 month', '-1 week'),
        ]);
    }

    /**
     * Associate with a specific deal.
     */
    public function forDeal(Deal $deal): static
    {
        return $this->state(fn (array $attributes) => [
            'deal_id' => $deal->id,
        ]);
    }

    /**
     * Apply a margin discount to the estimate.
     */
    public function withMarginDiscount(float $percent = 10): static
    {
        return $this->state(fn () => ['margin_discount_percent' => $percent]);
    }

    /**
     * Set a per-estimate hourly rate override.
     */
    public function withHourlyRateOverride(float $rate = 18.00): static
    {
        return $this->state(fn () => ['hourly_rate_override' => $rate]);
    }

    /**
     * Set high value estimate.
     */
    public function highValue(): static
    {
        $subtotal = fake()->randomFloat(2, 25000, 100000);
        $taxRate = fake()->randomElement([6, 7, 8, 10]);
        $taxAmount = $subtotal * ($taxRate / 100);
        $total = $subtotal + $taxAmount;

        return $this->state(fn (array $attributes) => [
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ]);
    }
}
