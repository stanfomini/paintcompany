<?php

namespace App\Mcp\Resources\Workflow;

use App\Services\WorkflowAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class AvailableConditionsResource extends Resource
{
    protected string $name = 'available_conditions';

    protected string $uri = 'workflow://conditions';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All available workflow conditions (if/else) with their names, categories, descriptions, and config schemas.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        return Response::json($service->listConditions());
    }
}
