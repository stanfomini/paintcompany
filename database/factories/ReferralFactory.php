<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Referral>
 */
class ReferralFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'referrer_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'deal_id' => null,
            'status' => Referral::STATUS_LEAD,
            'reward_amount' => null,
            'reward_sent_at' => null,
            'reward_notes' => null,
        ];
    }

    /**
     * Set the referral status to quoted.
     */
    public function quoted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Referral::STATUS_QUOTED,
        ]);
    }

    /**
     * Set the referral status to signed.
     */
    public function signed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Referral::STATUS_SIGNED,
            'deal_id' => Deal::factory(),
        ]);
    }

    /**
     * Set the referral status to deposit paid (reward earned).
     */
    public function depositPaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Referral::STATUS_DEPOSIT_PAID,
            'deal_id' => Deal::factory(),
            'reward_amount' => $attributes['reward_amount'] ?? 50.00,
        ]);
    }

    /**
     * Set the referral status to reward sent.
     */
    public function rewardSent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Referral::STATUS_REWARD_SENT,
            'deal_id' => Deal::factory(),
            'reward_amount' => $attributes['reward_amount'] ?? 50.00,
            'reward_sent_at' => now(),
        ]);
    }
}
