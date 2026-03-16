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
        $definition = [
            'name' => fake()->words(3, true).' Flow',
            'status' => 'draft',
            'trigger_type' => 'new_lead',
            'trigger_keyword' => null,
            'lead_source_ids' => null,
            'completion_action' => null,
            'timeout_minutes' => 10080,
            'business_hours_only' => false,
            'delay_after_lead_minutes' => 5,
        ];

        // SQLite doesn't drop columns, so these are still required in tests
        if (\DB::getDriverName() === 'sqlite') {
            $definition['greeting_message'] = fake()->sentence();
            $definition['photo_prompt_message'] = fake()->sentence();
            $definition['visualization_message'] = fake()->sentence();
            $definition['portal_link_message'] = fake()->sentence();
        }

        return $definition;
    }

    public function keyword(string $keyword = 'COLOR'): static
    {
        return $this->state([
            'trigger_type' => 'keyword',
            'trigger_keyword' => strtoupper($keyword),
        ]);
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
