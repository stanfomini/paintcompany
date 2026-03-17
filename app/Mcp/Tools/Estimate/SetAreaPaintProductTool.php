<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SetAreaPaintProductTool extends Tool
{
    protected string $name = 'set_area_paint_product';

    protected string $description = <<<'MARKDOWN'
        Set the paint product for a specific area. Optionally provide category overrides to use different products per surface type within this area. Clears cached pricing to trigger recalculation.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'area_id' => 'required|integer|exists:estimate_areas,id',
            'paint_product_id' => 'nullable|integer|exists:paint_products,id',
            'category_overrides' => 'nullable|array',
            'category_overrides.walls' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.ceiling' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.trim' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.doors' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.baseboard' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.body' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.fascia' => 'nullable|integer|exists:paint_products,id',
            'category_overrides.eaves' => 'nullable|integer|exists:paint_products,id',
        ]);

        $result = $service->setAreaPaintProduct(
            areaId: $data['area_id'],
            paintProductId: $data['paint_product_id'] ?? null,
            categoryOverrides: $data['category_overrides'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('The area ID to set paint product for')->required(),
            'paint_product_id' => $schema->integer()->description('Default paint product ID for all surfaces in this area'),
            'category_overrides' => $schema->object()->description('Map of surface category to paint product ID for per-category overrides'),
        ];
    }
}
