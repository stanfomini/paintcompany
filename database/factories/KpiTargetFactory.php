<?php

namespace Database\Factories;

use App\Models\KpiMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KpiTarget>
 */
class KpiTargetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-6 months', 'now');
        $periodEnd = (clone $periodStart)->modify('+1 month');

        return [
            'kpi_metric_id' => KpiMetric::factory(),
            'target_value' => fake()->randomFloat(2, 10, 1000),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forMetric(KpiMetric $metric): static
    {
        return $this->state(fn (array $attributes) => [
            'kpi_metric_id' => $metric->id,
        ]);
    }

    public function thisWeek(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => now()->startOfWeek(),
            'period_end' => now()->endOfWeek(),
        ]);
    }

    public function thisMonth(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);
    }

    public function thisQuarter(): static
    {
        return $this->state(fn (array $attributes) => [
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
        ]);
    }
}
