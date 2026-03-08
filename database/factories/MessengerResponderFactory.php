<?php

namespace Database\Factories;

use App\Models\MessengerResponder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MessengerResponder>
 */
class MessengerResponderFactory extends Factory
{
    protected $model = MessengerResponder::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->internal(),
            'role' => 'estimator',
            'is_active' => true,
            'sort_order' => 0,
            'last_assigned_at' => null,
        ];
    }

    public function estimator(): static
    {
        return $this->state(['role' => 'estimator']);
    }

    public function booker(): static
    {
        return $this->state(['role' => 'appointment_booker']);
    }

    public function both(): static
    {
        return $this->state(['role' => 'both']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
