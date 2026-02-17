<?php

namespace Database\Factories;

use App\Models\PayPeriod;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayPeriod>
 */
class PayPeriodFactory extends Factory
{
    protected $model = PayPeriod::class;

    public function definition(): array
    {
        $startDate = fake()->dateTimeBetween('-3 months', 'now');
        $type = fake()->randomElement(['weekly', 'biweekly', 'semimonthly', 'monthly']);

        $daysToAdd = match ($type) {
            'weekly' => 6,
            'biweekly' => 13,
            'semimonthly' => 14,
            'monthly' => 29,
        };

        return [
            'start_date' => $startDate,
            'end_date' => (clone $startDate)->modify("+{$daysToAdd} days"),
            'type' => $type,
            'status' => 'open',
        ];
    }

    /**
     * Pay period that has been completed/processed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processed_by' => User::factory()->internal(),
            'processed_at' => now(),
        ]);
    }

    /**
     * Pay period currently being processed.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
        ]);
    }

    /**
     * Weekly pay period.
     */
    public function weekly(): static
    {
        return $this->state(function (array $attributes) {
            $start = $attributes['start_date'];

            return [
                'type' => 'weekly',
                'end_date' => (clone $start)->modify('+6 days'),
            ];
        });
    }

    /**
     * Biweekly pay period.
     */
    public function biweekly(): static
    {
        return $this->state(function (array $attributes) {
            $start = $attributes['start_date'];

            return [
                'type' => 'biweekly',
                'end_date' => (clone $start)->modify('+13 days'),
            ];
        });
    }
}
