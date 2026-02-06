<?php

namespace Database\Factories;

use App\Models\KpiMetric;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\KpiActual>
 */
class KpiActualFactory extends Factory
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
            'actual_value' => fake()->randomFloat(2, 5, 1200),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'is_manual' => true,
            'entered_by' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forMetric(KpiMetric $metric): static
    {
        return $this->state(fn (array $attributes) => [
            'kpi_metric_id' => $metric->id,
        ]);
    }

    public function enteredBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'entered_by' => $user->id,
            'is_manual' => true,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => true,
        ]);
    }

    public function calculated(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_manual' => false,
            'entered_by' => null,
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
}
