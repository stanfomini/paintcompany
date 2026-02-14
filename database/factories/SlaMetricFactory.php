<?php

namespace Database\Factories;

use App\Models\SlaMetric;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlaMetric>
 */
class SlaMetricFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->internal(),
            'metric_type' => fake()->randomElement(array_keys(SlaMetric::METRIC_TYPES)),
            'period' => 'daily',
            'period_date' => fake()->dateTimeBetween('-30 days', 'now'),
            'value' => fake()->randomFloat(2, 0, 100),
            'sample_count' => fake()->numberBetween(1, 50),
        ];
    }

    public function speedToLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric_type' => 'speed_to_lead',
            'value' => fake()->randomFloat(2, 5, 120), // minutes
        ]);
    }

    public function followUpCompliance(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric_type' => 'follow_up_compliance',
            'value' => fake()->randomFloat(2, 50, 100), // percentage
        ]);
    }

    public function responseTime(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric_type' => 'response_time',
            'value' => fake()->randomFloat(2, 5, 240), // minutes
        ]);
    }

    public function estimateTurnaround(): static
    {
        return $this->state(fn (array $attributes) => [
            'metric_type' => 'estimate_turnaround',
            'value' => fake()->randomFloat(2, 1, 72), // hours
        ]);
    }

    public function daily(): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => 'daily',
        ]);
    }

    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => 'weekly',
        ]);
    }

    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => 'monthly',
        ]);
    }
}
