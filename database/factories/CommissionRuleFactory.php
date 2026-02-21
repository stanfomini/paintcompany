<?php

namespace Database\Factories;

use App\Models\CommissionRule;
use App\Models\EmployeeProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommissionRule>
 */
class CommissionRuleFactory extends Factory
{
    protected $model = CommissionRule::class;

    public function definition(): array
    {
        return [
            'commissionable_type' => EmployeeProfile::class,
            'commissionable_id' => EmployeeProfile::factory(),
            'name' => '5% on estimates',
            'source' => 'estimate',
            'commission_type' => 'revenue_percentage',
            'rate' => 0.05,
            'amount' => 0,
            'conditions' => null,
            'auto_trigger' => true,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Flat commission rule.
     */
    public function flat(float $amount = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => "Flat \${$amount}",
            'commission_type' => 'flat',
            'rate' => 0,
            'amount' => $amount,
        ]);
    }

    /**
     * Revenue percentage commission rule.
     */
    public function revenuePercentage(float $rate = 0.05): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => ($rate * 100).'% of revenue',
            'commission_type' => 'revenue_percentage',
            'rate' => $rate,
            'amount' => 0,
        ]);
    }

    /**
     * Profit percentage commission rule.
     */
    public function profitPercentage(float $rate = 0.10): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => ($rate * 100).'% of profit',
            'commission_type' => 'profit_percentage',
            'rate' => $rate,
            'amount' => 0,
        ]);
    }

    /**
     * Commission rule for a specific source.
     */
    public function forSource(string $source): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => $source,
        ]);
    }

    /**
     * Inactive commission rule.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Manual-only commission rule (not auto-triggered).
     */
    public function manualOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'auto_trigger' => false,
        ]);
    }
}
