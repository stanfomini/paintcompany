<?php

namespace App\Mcp\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RemoveWorkflowNodeTool extends Tool
{
    protected string $name = 'remove_workflow_node';

    protected string $description = <<<'MARKDOWN'
        Delete a workflow node and all its connected edges (both incoming and outgoing).
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'node_id' => 'required|integer|exists:workflow_nodes,id',
        ]);

        $result = $service->removeNode($data['node_id']);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->description('The node ID to delete')->required(),
        ];
    }
}
