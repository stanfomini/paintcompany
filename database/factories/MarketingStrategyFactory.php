<?php

namespace Database\Factories;

use App\Models\MarketingStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketingStrategyFactory extends Factory
{
    protected $model = MarketingStrategy::class;

    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'category' => fake()->randomElement(['automated', 'paid_ads', 'new_homeowners', 'partnerships']),
            'name' => fake()->words(3, true),
            'status' => 'not_started',
            'configuration' => null,
            'notes' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => 'paused',
            'paused_at' => now(),
        ]);
    }

    public function automated(): static
    {
        return $this->state(fn () => ['category' => 'automated']);
    }

    public function paidAds(): static
    {
        return $this->state(fn () => ['category' => 'paid_ads']);
    }

    public function newHomeowners(): static
    {
        return $this->state(fn () => ['category' => 'new_homeowners']);
    }

    public function partnerships(): static
    {
        return $this->state(fn () => ['category' => 'partnerships']);
    }
}
