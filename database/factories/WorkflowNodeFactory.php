<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowNode>
 */
class WorkflowNodeFactory extends Factory
{
    protected $model = WorkflowNode::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'type' => fake()->randomElement(['trigger', 'action', 'if_else', 'wait', 'goal', 'goto']),
            'node_class' => 'App\\Workflows\\Nodes\\GenericNode',
            'config' => null,
            'position_x' => fake()->randomFloat(1, 0, 1000),
            'position_y' => fake()->randomFloat(1, 0, 1000),
            'label' => fake()->optional()->words(3, true),
            'is_enabled' => true,
        ];
    }

    /**
     * Set the node type to trigger.
     */
    public function trigger(): static
    {
        return $this->state(fn () => [
            'type' => 'trigger',
        ]);
    }

    /**
     * Set the node type to action.
     */
    public function action(): static
    {
        return $this->state(fn () => [
            'type' => 'action',
        ]);
    }

    /**
     * Set the node type to if_else.
     */
    public function ifElse(): static
    {
        return $this->state(fn () => [
            'type' => 'if_else',
        ]);
    }

    /**
     * Set the node type to wait.
     */
    public function wait(): static
    {
        return $this->state(fn () => [
            'type' => 'wait',
        ]);
    }

    /**
     * Mark the node as disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn () => [
            'is_enabled' => false,
        ]);
    }
}
