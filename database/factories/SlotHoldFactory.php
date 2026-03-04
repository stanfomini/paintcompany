<?php

namespace Database\Factories;

use App\Models\SlotHold;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SlotHold>
 */
class SlotHoldFactory extends Factory
{
    protected $model = SlotHold::class;

    public function definition(): array
    {
        return [
            'session_id' => (string) Str::uuid(),
            'estimator_id' => User::factory()->internal(),
            'hold_date' => fake()->dateTimeBetween('+1 day', '+30 days'),
            'hold_start_time' => '09:00',
            'hold_end_time' => '10:00',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
