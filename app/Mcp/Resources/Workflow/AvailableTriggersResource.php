<?php

namespace App\Mcp\Resources\Workflow;

use App\Services\WorkflowAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class AvailableTriggersResource extends Resource
{
    protected string $name = 'available_triggers';

    protected string $uri = 'workflow://triggers';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All available workflow triggers with their names, categories, descriptions, config schemas, and dynamic value prefixes.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        return Response::json($service->listTriggers());
    }
}
