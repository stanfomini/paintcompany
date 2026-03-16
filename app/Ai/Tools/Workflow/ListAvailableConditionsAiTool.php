<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListAvailableConditionsAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List all available workflow conditions (if/else branches). Returns class names, display names, categories, descriptions, and config schemas.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        return json_encode($service->listConditions());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
