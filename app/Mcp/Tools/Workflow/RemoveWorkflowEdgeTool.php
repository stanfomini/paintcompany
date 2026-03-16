<?php

namespace App\Mcp\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RemoveWorkflowEdgeTool extends Tool
{
    protected string $name = 'remove_workflow_edge';

    protected string $description = <<<'MARKDOWN'
        Delete a specific edge (connection) between two workflow nodes.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'edge_id' => 'required|integer|exists:workflow_edges,id',
        ]);

        $result = $service->removeEdge($data['edge_id']);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'edge_id' => $schema->integer()->description('The edge ID to delete')->required(),
        ];
    }
}
