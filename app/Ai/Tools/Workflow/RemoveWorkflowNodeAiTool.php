<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RemoveWorkflowNodeAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Delete a workflow node and all its connected edges.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);
        $result = $service->removeNode((int) $request['node_id']);

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->description('The node ID to delete')->required(),
        ];
    }
}
