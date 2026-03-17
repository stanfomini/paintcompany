<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RemoveAreaTool extends Tool
{
    protected string $name = 'remove_area';

    protected string $description = <<<'MARKDOWN'
        Remove an area and all its surfaces, addons, and child areas. Estimate totals are automatically recalculated.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'area_id' => 'required|integer|exists:estimate_areas,id',
        ]);

        $result = $service->removeArea($data['area_id']);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('The area ID to remove')->required(),
        ];
    }
}
