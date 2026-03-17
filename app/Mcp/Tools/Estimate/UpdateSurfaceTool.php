<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateSurfaceTool extends Tool
{
    protected string $name = 'update_surface';

    protected string $description = <<<'MARKDOWN'
        Update a surface's configuration including production rate, number of coats, paint product, or quantity. Pricing is automatically recalculated.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'surface_id' => 'required|integer|exists:estimate_area_surfaces,id',
            'production_rate_id' => 'nullable|integer|exists:production_rates,id',
            'coats' => 'nullable|integer|min:1|max:5',
            'paint_product_id' => 'nullable|integer|exists:paint_products,id',
            'quantity_override' => 'nullable|numeric|min:0',
        ]);

        $result = $service->updateSurface(
            surfaceId: $data['surface_id'],
            productionRateId: $data['production_rate_id'] ?? null,
            coats: $data['coats'] ?? null,
            paintProductId: $data['paint_product_id'] ?? null,
            quantityOverride: isset($data['quantity_override']) ? (float) $data['quantity_override'] : null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'surface_id' => $schema->integer()->description('The surface ID to update')->required(),
            'production_rate_id' => $schema->integer()->description('New production rate ID'),
            'coats' => $schema->integer()->description('Number of paint coats (1-5)'),
            'paint_product_id' => $schema->integer()->description('Paint product ID to assign'),
            'quantity_override' => $schema->number()->description('Override the surface quantity (sqft, lnft, or units)'),
        ];
    }
}
