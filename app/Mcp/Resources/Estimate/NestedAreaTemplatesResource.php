<?php

namespace App\Mcp\Resources\Estimate;

use App\Services\EstimateAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class NestedAreaTemplatesResource extends Resource
{
    protected string $name = 'nested_area_templates';

    protected string $uri = 'estimate://addons';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All active nested area templates (fixed-price addons) such as closets, accent walls, half baths, and other pre-priced items. Use template IDs with the add_addon_to_area tool.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        return Response::json($service->listNestedTemplates());
    }
}
