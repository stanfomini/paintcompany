<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowTrigger;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowTrigger>
 */
class WorkflowTriggerFactory extends Factory
{
    protected $model = WorkflowTrigger::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'trigger_model' => 'deal',
            'trigger_event' => 'status_changed',
            'trigger_field' => 'stage',
            'trigger_conditions' => null,
            'order' => 1,
        ];
    }

    /**
     * Set the trigger for a specific model type.
     */
    public function forModel(string $modelType): static
    {
        return $this->state(fn () => [
            'trigger_model' => $modelType,
        ]);
    }

    /**
     * Set the trigger event.
     */
    public function onEvent(string $event): static
    {
        return $this->state(fn () => [
            'trigger_event' => $event,
        ]);
    }

    /**
     * Set trigger conditions.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function withConditions(array $conditions): static
    {
        return $this->state(fn () => [
            'trigger_conditions' => $conditions,
        ]);
    }
}
