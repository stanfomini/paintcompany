<?php

namespace Database\Factories;

use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowRunStep>
 */
class WorkflowRunStepFactory extends Factory
{
    protected $model = WorkflowRunStep::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_run_id' => WorkflowRun::factory(),
            'workflow_node_id' => WorkflowNode::factory(),
            'status' => 'pending',
            'scheduled_for' => null,
            'started_at' => null,
            'completed_at' => null,
            'result' => null,
            'error_message' => null,
        ];
    }

    /**
     * Mark the step as executing.
     */
    public function executing(): static
    {
        return $this->state(fn () => [
            'status' => 'executing',
            'started_at' => now(),
        ]);
    }

    /**
     * Mark the step as waiting with a scheduled time.
     */
    public function waiting(): static
    {
        return $this->state(fn () => [
            'status' => 'waiting',
            'scheduled_for' => now()->addHours(fake()->numberBetween(1, 48)),
        ]);
    }

    /**
     * Mark the step as completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the step as skipped.
     */
    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the step as failed.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'error_message' => fake()->sentence(),
        ]);
    }
}
