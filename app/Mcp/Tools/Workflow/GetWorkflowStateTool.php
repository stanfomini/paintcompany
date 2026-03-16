<?php

namespace App\Mcp\Tools\Workflow;

use App\Models\Workflow;
use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetWorkflowStateTool extends Tool
{
    protected string $name = 'get_workflow_state';

    protected string $description = <<<'MARKDOWN'
        Get the current state of a workflow including all nodes and edges. Returns node IDs, types, classes, labels, configs, positions, and edge connections.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'workflow_id' => 'required|integer|exists:workflows,id',
        ]);

        $workflow = Workflow::findOrFail($data['workflow_id']);
        $result = $service->getWorkflowState($workflow);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->integer()->description('The workflow ID to inspect')->required(),
        ];
    }
}
