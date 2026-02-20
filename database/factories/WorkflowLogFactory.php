<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\Workflow;
use App\Models\WorkflowLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowLog>
 */
class WorkflowLogFactory extends Factory
{
    protected $model = WorkflowLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'trigger_model_type' => Deal::class,
            'trigger_model_id' => Deal::factory(),
            'target_user_id' => null,
            'status' => WorkflowLog::STATUS_SUCCESS,
            'trigger_snapshot' => null,
            'actions_executed' => null,
            'error_message' => null,
            'is_manual' => false,
            'created_at' => now(),
        ];
    }

    /**
     * Mark the log as failed.
     */
    public function failed(?string $error = null): static
    {
        return $this->state(fn () => [
            'status' => WorkflowLog::STATUS_FAILED,
            'error_message' => $error ?? 'Test failure',
        ]);
    }

    /**
     * Mark the log as skipped.
     */
    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => WorkflowLog::STATUS_SKIPPED,
        ]);
    }

    /**
     * Mark the log as manually triggered.
     */
    public function manual(): static
    {
        return $this->state(fn () => [
            'is_manual' => true,
        ]);
    }
}
