<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListAvailableActionsAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List all available workflow actions. Returns class names, display names, categories, descriptions, and config schemas. Call this to find actions you can add to the workflow.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        return json_encode($service->listActions());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
