<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddSurfaceToAreaTool extends Tool
{
    protected string $name = 'add_surface_to_area';

    protected string $description = <<<'MARKDOWN'
        Add a paintable surface to an area. Quantity is auto-calculated from area dimensions based on the surface category (walls=wall_sqft, ceiling=ceiling_sqft, trim=perimeter_lnft, doors/windows=1 unit). Use quantity_override for manual values.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'area_id' => 'required|integer|exists:estimate_areas,id',
            'surface_category' => 'required|string|in:walls,wall,ceiling,baseboard,trim,doors,exterior_doors,windows,body,siding,fascia,eaves,crown,molding',
            'production_rate_id' => 'required|integer|exists:production_rates,id',
            'coats' => 'nullable|integer|min:1|max:5',
            'quantity_override' => 'nullable|numeric|min:0',
        ]);

        $result = $service->addSurfaceToArea(
            areaId: $data['area_id'],
            surfaceCategory: $data['surface_category'],
            productionRateId: $data['production_rate_id'],
            coats: $data['coats'] ?? 2,
            quantityOverride: isset($data['quantity_override']) ? (float) $data['quantity_override'] : null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('The area ID to add the surface to')->required(),
            'surface_category' => $schema->string()->description('Surface category: walls, ceiling, baseboard, trim, doors, windows, body, siding, fascia, eaves, crown, molding')->required(),
            'production_rate_id' => $schema->integer()->description('Production rate ID for this surface (see production_rates resource)')->required(),
            'coats' => $schema->integer()->description('Number of paint coats (default: 2)'),
            'quantity_override' => $schema->number()->description('Override the auto-calculated quantity (sqft, lnft, or units)'),
        ];
    }
}
