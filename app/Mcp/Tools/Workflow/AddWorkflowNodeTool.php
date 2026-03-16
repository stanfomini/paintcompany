<?php

namespace App\Mcp\Tools\Workflow;

use App\Models\Workflow;
use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddWorkflowNodeTool extends Tool
{
    protected string $name = 'add_workflow_node';

    protected string $description = <<<'MARKDOWN'
        Add a new node to a workflow. Nodes can be triggers, actions, conditions (if_else), waits, goals, or gotos. The node will be auto-positioned and auto-connected to the last leaf node if one exists.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'workflow_id' => 'required|integer|exists:workflows,id',
            'type' => 'required|string|in:trigger,action,if_else,wait,goal,goto',
            'node_class' => 'required|string',
            'label' => 'nullable|string|max:255',
            'x' => 'nullable|numeric',
            'y' => 'nullable|numeric',
        ]);

        $workflow = Workflow::findOrFail($data['workflow_id']);

        $result = $service->addNode(
            workflow: $workflow,
            type: $data['type'],
            nodeClass: $data['node_class'],
            label: $data['label'] ?? null,
            x: isset($data['x']) ? (float) $data['x'] : null,
            y: isset($data['y']) ? (float) $data['y'] : null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->integer()->description('The workflow ID to add the node to')->required(),
            'type' => $schema->string()->description('Node type: trigger, action, if_else, wait, goal, or goto')->required(),
            'node_class' => $schema->string()->description('Fully qualified class name of the node implementation (e.g., App\\Automation\\Actions\\SendEmail)')->required(),
            'label' => $schema->string()->description('Optional custom label for the node'),
            'x' => $schema->number()->description('Optional X position on canvas (snaps to 20px grid)'),
            'y' => $schema->number()->description('Optional Y position on canvas (snaps to 20px grid)'),
        ];
    }
}
