<?php

namespace Database\Factories;

use App\Models\MessengerBotFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessengerBotFlowFactory extends Factory
{
    protected $model = MessengerBotFlow::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Flow',
            'status' => 'draft',
            'greeting_message' => fake()->sentence(),
            'completion_action' => 'create_lead',
            'timeout_minutes' => 30,
            'business_hours_only' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
