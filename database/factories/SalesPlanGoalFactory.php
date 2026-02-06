<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesPlanGoal>
 */
class SalesPlanGoalFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'year' => now()->year,
            'annual_revenue_goal' => fake()->randomElement([500000, 750000, 1000000, 1500000]),
            'avg_job_size' => fake()->randomElement([3000, 4000, 5000, 6000]),
            'lead_conversion_rate' => fake()->randomFloat(2, 50, 80), // 50-80%
            'estimate_close_rate' => fake()->randomFloat(2, 40, 70), // 40-70%
            'notes' => null,
        ];
    }

    /**
     * Configure for a specific year.
     */
    public function forYear(int $year): static
    {
        return $this->state(fn () => ['year' => $year]);
    }

    /**
     * Configure with a $1.5M goal (common target).
     */
    public function millionFifty(): static
    {
        return $this->state(fn () => [
            'annual_revenue_goal' => 1500000,
            'avg_job_size' => 5000,
            'lead_conversion_rate' => 65,
            'estimate_close_rate' => 55,
        ]);
    }
}
