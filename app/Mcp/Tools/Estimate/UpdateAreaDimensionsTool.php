<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateAreaDimensionsTool extends Tool
{
    protected string $name = 'update_area_dimensions';

    protected string $description = <<<'MARKDOWN'
        Update an area's dimensions (length, width, height, wall count). Surface quantities are automatically recalculated based on the new dimensions.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'area_id' => 'required|integer|exists:estimate_areas,id',
            'length' => 'nullable|numeric|min:0.1',
            'width' => 'nullable|numeric|min:0.1',
            'height' => 'nullable|numeric|min:0.1',
            'wall_count' => 'nullable|integer|min:1|max:20',
        ]);

        $result = $service->updateAreaDimensions(
            areaId: $data['area_id'],
            length: isset($data['length']) ? (float) $data['length'] : null,
            width: isset($data['width']) ? (float) $data['width'] : null,
            height: isset($data['height']) ? (float) $data['height'] : null,
            wallCount: $data['wall_count'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('The area ID to update dimensions for')->required(),
            'length' => $schema->number()->description('New room length in feet'),
            'width' => $schema->number()->description('New room width in feet'),
            'height' => $schema->number()->description('New wall height in feet'),
            'wall_count' => $schema->integer()->description('New number of walls (1-20)'),
        ];
    }
}
