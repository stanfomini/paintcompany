<?php

namespace App\Ai\Tools\Workflow;

use App\Models\Workflow;
use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddWorkflowNodeAiTool implements Tool
{
    public function __construct(
        protected Workflow $workflow,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a new node to the workflow. Types: trigger, action, if_else, wait, goal, goto. The node_class must be a fully qualified class name from the available triggers/actions/conditions/waits. The node will be auto-positioned and auto-connected to the last leaf node.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        try {
            $result = $service->addNode(
                workflow: $this->workflow->fresh(),
                type: $request['type'],
                nodeClass: $request['node_class'],
                label: $request['label'] ?? null,
                x: isset($request['x']) ? (float) $request['x'] : null,
                y: isset($request['y']) ? (float) $request['y'] : null,
            );

            return json_encode($result);
        } catch (\InvalidArgumentException $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->description('Node type: trigger, action, if_else, wait, goal, or goto')->required(),
            'node_class' => $schema->string()->description('Fully qualified class name (e.g., App\\Automation\\Actions\\SendEmail)')->required(),
            'label' => $schema->string()->description('Optional custom display label'),
            'x' => $schema->number()->description('Optional X position (default: 250)'),
            'y' => $schema->number()->description('Optional Y position (default: auto-stacked)'),
        ];
    }
}
