<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DealCollaborator>
 */
class DealCollaboratorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'customer_id' => Customer::factory()->withPortalAccess(),
            'invited_by' => User::factory()->customer(),
            'expires_at' => now()->addDays(30),
        ];
    }

    /**
     * Create an expired collaboration.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Create a revoked collaboration.
     */
    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'revoked_at' => now(),
        ]);
    }

    /**
     * Set specific deal.
     */
    public function forDeal(Deal $deal): static
    {
        return $this->state(fn (array $attributes) => [
            'deal_id' => $deal->id,
            'invited_by' => $deal->customer?->user_id ?? User::factory()->customer(),
        ]);
    }

    /**
     * Set specific collaborator customer.
     */
    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }
}
