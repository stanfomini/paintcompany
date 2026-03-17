<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RemoveSurfaceTool extends Tool
{
    protected string $name = 'remove_surface';

    protected string $description = <<<'MARKDOWN'
        Remove a surface from an area. The area's cached pricing is cleared to trigger recalculation.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'surface_id' => 'required|integer|exists:estimate_area_surfaces,id',
        ]);

        $result = $service->removeSurface($data['surface_id']);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'surface_id' => $schema->integer()->description('The surface ID to remove')->required(),
        ];
    }
}
