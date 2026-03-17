<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SetEstimatePaintConfigTool extends Tool
{
    protected string $name = 'set_estimate_paint_config';

    protected string $description = <<<'MARKDOWN'
        Set the paint configuration for the entire estimate. Use mode "single" to apply one paint product to all surfaces, or "category" to set different products per surface category (walls, ceiling, trim, etc.). Propagates to all existing surfaces and recalculates totals.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
            'mode' => 'required|string|in:single,category',
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

        $estimate = Estimate::findOrFail($data['estimate_id']);

        $result = $service->setEstimatePaintConfig(
            estimate: $estimate,
            mode: $data['mode'],
            paintProductId: $data['paint_product_id'] ?? null,
            categoryOverrides: $data['category_overrides'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to configure paint for')->required(),
            'mode' => $schema->string()->description('Paint assignment mode: "single" (one product for all) or "category" (different products per surface type)')->required(),
            'paint_product_id' => $schema->integer()->description('Paint product ID for single mode, or default fallback for category mode'),
            'category_overrides' => $schema->object()->description('Map of surface category to paint product ID (e.g., {"walls": 5, "ceiling": 8, "trim": 12})'),
        ];
    }
}
