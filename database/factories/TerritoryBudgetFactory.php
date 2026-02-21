<?php

namespace Database\Factories;

use App\Models\SalesTerritory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TerritoryBudget>
 */
class TerritoryBudgetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'territory_id' => SalesTerritory::factory(),
            'period_type' => $this->faker->randomElement(['monthly', 'quarterly', 'yearly']),
            'period_start' => now()->startOfMonth(),
            'marketing_budget' => $this->faker->randomFloat(2, 500, 5000),
            'commission_budget_pct' => 12.00,
        ];
    }

    public function monthly(): static
    {
        return $this->state(fn () => ['period_type' => 'monthly']);
    }

    public function quarterly(): static
    {
        return $this->state(fn () => ['period_type' => 'quarterly']);
    }

    public function yearly(): static
    {
        return $this->state(fn () => ['period_type' => 'yearly']);
    }
}
