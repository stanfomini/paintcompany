<?php

namespace App\Ai\Tools\Workflow;

use App\Models\Workflow;
use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ConnectWorkflowNodesAiTool implements Tool
{
    public function __construct(
        protected Workflow $workflow,
    ) {}

    public function description(): Stringable|string
    {
        return 'Connect two nodes with a directed edge. For if_else nodes, specify branch as "true" or "false". Prevents duplicate edges.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        $result = $service->connectNodes(
            workflow: $this->workflow->fresh(),
            fromNodeId: (int) $request['from_node_id'],
            toNodeId: (int) $request['to_node_id'],
            branch: $request['branch'] ?? null,
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_node_id' => $schema->integer()->description('Source node ID')->required(),
            'to_node_id' => $schema->integer()->description('Target node ID')->required(),
            'branch' => $schema->string()->description('For if_else nodes: "true" or "false" branch'),
        ];
    }
}
