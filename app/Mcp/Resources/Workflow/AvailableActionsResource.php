<?php

namespace App\Mcp\Resources\Workflow;

use App\Services\WorkflowAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class AvailableActionsResource extends Resource
{
    protected string $name = 'available_actions';

    protected string $uri = 'workflow://actions';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All available workflow actions with their names, categories, descriptions, and config schemas.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        return Response::json($service->listActions());
    }
}
