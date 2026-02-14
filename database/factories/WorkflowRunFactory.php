<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    protected $model = WorkflowRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'status' => 'running',
            'context' => null,
            'current_node_id' => null,
            'triggered_at' => now(),
            'completed_at' => null,
            'error_message' => null,
            'triggered_by' => User::factory(),
        ];
    }

    /**
     * Mark the run as completed.
     */
    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the run as waiting.
     */
    public function waiting(): static
    {
        return $this->state(fn () => [
            'status' => 'waiting',
        ]);
    }

    /**
     * Mark the run as failed.
     */
    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error_message' => fake()->sentence(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the run as cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
    }
}
