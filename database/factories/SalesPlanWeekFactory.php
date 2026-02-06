<?php

namespace Database\Factories;

use App\Models\CompanySetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SalesPlanWeek>
 */
class SalesPlanWeekFactory extends Factory
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
            'leads_plan' => fake()->numberBetween(5, 15),
            'leads_actual' => fake()->numberBetween(3, 18),
            'estimates_plan' => fake()->numberBetween(3, 10),
            'estimates_actual' => fake()->numberBetween(2, 12),
            'jobs_booked_plan' => fake()->numberBetween(2, 6),
            'jobs_booked_actual' => fake()->numberBetween(1, 8),
            'revenue_plan' => fake()->randomFloat(2, 10000, 40000),
            'revenue_actual' => fake()->randomFloat(2, 8000, 50000),
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
     * Configure with actuals exceeding plan (good week).
     */
    public function exceedsTarget(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'leads_actual' => $attributes['leads_plan'] + fake()->numberBetween(1, 5),
                'estimates_actual' => $attributes['estimates_plan'] + fake()->numberBetween(1, 3),
                'jobs_booked_actual' => $attributes['jobs_booked_plan'] + fake()->numberBetween(0, 2),
                'revenue_actual' => $attributes['revenue_plan'] * fake()->randomFloat(2, 1.1, 1.5),
            ];
        });
    }

    /**
     * Configure with actuals below plan (bad week).
     */
    public function belowTarget(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'leads_actual' => max(0, $attributes['leads_plan'] - fake()->numberBetween(2, 5)),
                'estimates_actual' => max(0, $attributes['estimates_plan'] - fake()->numberBetween(1, 3)),
                'jobs_booked_actual' => max(0, $attributes['jobs_booked_plan'] - fake()->numberBetween(1, 2)),
                'revenue_actual' => $attributes['revenue_plan'] * fake()->randomFloat(2, 0.5, 0.9),
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
