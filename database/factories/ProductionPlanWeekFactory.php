<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProductionPlanWeek>
 */
class ProductionPlanWeekFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_setting_id' => CompanySetting::factory(),
            'year' => now()->year,
            'week_number' => fake()->numberBetween(1, 52),
            'revenue_plan' => fake()->randomFloat(2, 15000, 40000),
            'revenue_actual' => fake()->randomFloat(2, 10000, 50000),
            'jobs_completed_plan' => fake()->numberBetween(2, 5),
            'jobs_completed_actual' => fake()->numberBetween(1, 6),
            'labor_hours_plan' => fake()->randomFloat(2, 80, 200),
            'labor_hours_actual' => fake()->randomFloat(2, 60, 220),
            'notes' => null,
            'synced_at' => null,
        ];
    }

    /**
     * Configure for a specific week.
     */
    public function forWeek(int $week, ?int $year = null): static
    {
        return $this->state(fn () => [
            'week_number' => $week,
            'year' => $year ?? now()->year,
        ]);
    }

    /**
     * Configure with actuals exceeding plan (productive week).
     */
    public function exceedsTarget(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'revenue_actual' => $attributes['revenue_plan'] * fake()->randomFloat(2, 1.1, 1.5),
                'jobs_completed_actual' => $attributes['jobs_completed_plan'] + fake()->numberBetween(1, 2),
                'labor_hours_actual' => $attributes['labor_hours_plan'] * fake()->randomFloat(2, 1.0, 1.2),
            ];
        });
    }

    /**
     * Configure with actuals below plan (slow week).
     */
    public function belowTarget(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'revenue_actual' => $attributes['revenue_plan'] * fake()->randomFloat(2, 0.5, 0.9),
                'jobs_completed_actual' => max(0, $attributes['jobs_completed_plan'] - fake()->numberBetween(1, 2)),
                'labor_hours_actual' => $attributes['labor_hours_plan'] * fake()->randomFloat(2, 0.6, 0.9),
            ];
        });
    }

    /**
     * Configure as synced from app data.
     */
    public function synced(): static
    {
        return $this->state(fn () => ['synced_at' => now()]);
    }
}
