<?php

namespace App\Mcp\Resources\Estimate;

use App\Services\EstimateAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class EstimateTemplatesResource extends Resource
{
    protected string $name = 'estimate_templates';

    protected string $uri = 'estimate://templates';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All available room/area templates with their names, scope types, size options (small/medium/large with dimensions), surface definitions, and surface ratios. Use template IDs with the create_area_from_template tool.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        return Response::json($service->searchTemplates(''));
    }
}
