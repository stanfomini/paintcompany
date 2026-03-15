<?php

namespace Database\Factories;

use App\Models\SmsBotFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SmsBotFlow>
 */
class SmsBotFlowFactory extends Factory
{
    protected $model = SmsBotFlow::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Flow',
            'status' => 'draft',
            'trigger_type' => 'new_lead',
            'lead_source_ids' => null,
            'greeting_message' => fake()->sentence(),
            'photo_prompt_message' => fake()->sentence(),
            'visualization_message' => fake()->sentence(),
            'portal_link_message' => fake()->sentence(),
            'timeout_minutes' => 10080,
            'business_hours_only' => false,
            'delay_after_lead_minutes' => 5,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function draft(): static
    {
        return $this->state(['status' => 'draft']);
    }

    public function disabled(): static
    {
        return $this->state(['status' => 'disabled']);
    }
}
