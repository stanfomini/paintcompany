<?php

namespace App\Mcp\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ConfigureWorkflowNodeTool extends Tool
{
    protected string $name = 'configure_workflow_node';

    protected string $description = <<<'MARKDOWN'
        Update a node's configuration and optional label. Config is merged with existing config. Use dynamic values like {{contact.email}} in config values.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'node_id' => 'required|integer|exists:workflow_nodes,id',
            'config' => 'required|array',
            'label' => 'nullable|string|max:255',
        ]);

        $result = $service->configureNode(
            nodeId: $data['node_id'],
            config: $data['config'],
            label: $data['label'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->description('The node ID to configure')->required(),
            'config' => $schema->object()->description('Configuration key-value pairs to set or merge')->required(),
            'label' => $schema->string()->description('Optional custom label for the node'),
        ];
    }
}
