<?php

namespace App\Ai\Tools\Workflow;

use App\Models\Workflow;
use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetWorkflowStateAiTool implements Tool
{
    public function __construct(
        protected Workflow $workflow,
    ) {}

    public function description(): Stringable|string
    {
        return 'Get the current state of the workflow including all nodes (with IDs, types, classes, labels, configs, positions) and edges (with connections and branches). Call this to understand what exists before making changes.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);
        $result = $service->getWorkflowState($this->workflow->fresh());

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
