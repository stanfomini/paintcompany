<?php

namespace App\Mcp\Tools\Workflow;

use App\Models\Workflow;
use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ConnectWorkflowNodesTool extends Tool
{
    protected string $name = 'connect_workflow_nodes';

    protected string $description = <<<'MARKDOWN'
        Connect two nodes with a directed edge. For if_else (condition) nodes, use branch "true" or "false" to specify which output branch to connect. Prevents duplicate edges.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'workflow_id' => 'required|integer|exists:workflows,id',
            'from_node_id' => 'required|integer|exists:workflow_nodes,id',
            'to_node_id' => 'required|integer|exists:workflow_nodes,id',
            'branch' => 'nullable|string|in:true,false',
        ]);

        $workflow = Workflow::findOrFail($data['workflow_id']);

        $result = $service->connectNodes(
            workflow: $workflow,
            fromNodeId: $data['from_node_id'],
            toNodeId: $data['to_node_id'],
            branch: $data['branch'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->integer()->description('The workflow ID')->required(),
            'from_node_id' => $schema->integer()->description('Source node ID')->required(),
            'to_node_id' => $schema->integer()->description('Target node ID')->required(),
            'branch' => $schema->string()->description('For if_else nodes: "true" or "false" branch'),
        ];
    }
}
