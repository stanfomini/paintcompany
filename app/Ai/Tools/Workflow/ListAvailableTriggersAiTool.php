<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListAvailableTriggersAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List all available workflow triggers. Returns class names, display names, categories, descriptions, config schemas, and dynamic value prefixes. Call this FIRST when building a new workflow to choose a trigger.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        return json_encode($service->listTriggers());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
