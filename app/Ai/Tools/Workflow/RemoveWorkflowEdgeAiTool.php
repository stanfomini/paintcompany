<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RemoveWorkflowEdgeAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Delete a specific edge (connection) between two workflow nodes.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);
        $result = $service->removeEdge((int) $request['edge_id']);

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'edge_id' => $schema->integer()->description('The edge ID to delete')->required(),
        ];
    }
}
