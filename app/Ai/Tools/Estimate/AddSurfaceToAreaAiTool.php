<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddSurfaceToAreaAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a surface (walls, ceiling, trim, doors, etc.) to an area. Quantity auto-calculates from area dimensions based on category.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->addSurfaceToArea(
                areaId: (int) $request['area_id'],
                surfaceCategory: $request['surface_category'],
                productionRateId: (int) $request['production_rate_id'],
                coats: isset($request['coats']) ? (int) $request['coats'] : 2,
                quantityOverride: isset($request['quantity_override']) ? (float) $request['quantity_override'] : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('ID of the area to add the surface to')->required(),
            'surface_category' => $schema->string()->description('Surface category. Interior: walls|ceiling|baseboard|trim|doors|windows|prep. Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep. Do NOT use siding, crown, molding, or other.')->required(),
            'production_rate_id' => $schema->integer()->description('Production rate ID from list_production_rates')->required(),
            'coats' => $schema->integer()->description('Number of coats (default: 2)'),
            'quantity_override' => $schema->number()->description('Override auto-calculated quantity (sqft, lnft, or unit count)'),
        ];
    }
}
