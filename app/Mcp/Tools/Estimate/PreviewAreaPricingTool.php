<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class PreviewAreaPricingTool extends Tool
{
    protected string $name = 'preview_area_pricing';

    protected string $description = <<<'MARKDOWN'
        Preview detailed pricing breakdown for an area including per-surface labor hours, cleanup, sundries, materials, addon costs, total COGS, and customer price.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'area_id' => 'required|integer|exists:estimate_areas,id',
        ]);

        $result = $service->previewAreaPricing($data['area_id']);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('The area ID to preview pricing for')->required(),
        ];
    }
}
