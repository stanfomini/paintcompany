<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class SetTierPricingTool extends Tool
{
    protected string $name = 'set_tier_pricing';

    protected string $description = <<<'MARKDOWN'
        Configure Good/Better/Best tier pricing by assigning paint products per tier. Each tier can have a default product and optional per-category overrides. Material costs are recalculated for all surfaces at the tier level.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
            'tier' => 'required|string|in:good,better,best',
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

        $result = $service->setTierPricing(
            estimate: $estimate,
            tier: $data['tier'],
            paintProductId: $data['paint_product_id'] ?? null,
            categoryOverrides: $data['category_overrides'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to configure tier pricing for')->required(),
            'tier' => $schema->string()->description('Pricing tier: good, better, or best')->required(),
            'paint_product_id' => $schema->integer()->description('Default paint product ID for this tier'),
            'category_overrides' => $schema->object()->description('Map of surface category to paint product ID for per-category tier overrides'),
        ];
    }
}
