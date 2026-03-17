<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateSurfaceAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Update a surface\'s production rate, coats, paint product, or quantity. Recalculates labor and material costs.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->updateSurface(
                surfaceId: (int) $request['surface_id'],
                productionRateId: isset($request['production_rate_id']) ? (int) $request['production_rate_id'] : null,
                coats: isset($request['coats']) ? (int) $request['coats'] : null,
                paintProductId: isset($request['paint_product_id']) ? (int) $request['paint_product_id'] : null,
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
            'surface_id' => $schema->integer()->description('ID of the surface to update')->required(),
            'production_rate_id' => $schema->integer()->description('New production rate ID'),
            'coats' => $schema->integer()->description('New number of coats'),
            'paint_product_id' => $schema->integer()->description('New paint product ID'),
            'quantity_override' => $schema->number()->description('Override quantity (sqft, lnft, or unit count)'),
        ];
    }
}
