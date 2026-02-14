<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowEdge>
 */
class WorkflowEdgeFactory extends Factory
{
    protected $model = WorkflowEdge::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $workflow = Workflow::factory()->create();

        return [
            'workflow_id' => $workflow->id,
            'from_node_id' => WorkflowNode::factory()->state(['workflow_id' => $workflow->id]),
            'to_node_id' => WorkflowNode::factory()->state(['workflow_id' => $workflow->id]),
            'branch' => null,
            'condition_config' => null,
            'sort_order' => 0,
        ];
    }

    /**
     * Set the edge branch to 'yes' (for if/else true path).
     */
    public function yesBranch(): static
    {
        return $this->state(fn () => [
            'branch' => 'yes',
        ]);
    }

    /**
     * Set the edge branch to 'no' (for if/else false path).
     */
    public function noBranch(): static
    {
        return $this->state(fn () => [
            'branch' => 'no',
        ]);
    }
}
