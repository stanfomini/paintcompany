<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListAvailableWaitsAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List all available workflow wait nodes (time delays, date waits, condition waits). Returns class names, display names, categories, descriptions, and config schemas.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        return json_encode($service->listWaits());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
