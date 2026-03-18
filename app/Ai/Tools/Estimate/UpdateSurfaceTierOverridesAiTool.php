<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateSurfaceTierOverridesAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Set per-surface tier overrides for Good/Better/Best. USE THIS when tiers need different coats (e.g., Good=1 coat, Best=3 coats), different production rates, or different labor. Auto-computes labor hours from coats/rate and material cost from paint product. Call once per surface per tier.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->updateSurfaceTierOverrides(
                surfaceId: (int) $request['surface_id'],
                tier: $request['tier'],
                productionRateId: isset($request['production_rate_id']) ? (int) $request['production_rate_id'] : null,
                coats: isset($request['coats']) ? (int) $request['coats'] : null,
                paintProductId: isset($request['paint_product_id']) ? (int) $request['paint_product_id'] : null,
                laborHours: isset($request['labor_hours']) ? (float) $request['labor_hours'] : null,
                materialCost: isset($request['material_cost']) ? (float) $request['material_cost'] : null,
                estimate: $this->estimate,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'surface_id' => $schema->integer()->description('ID of the surface to configure')->required(),
            'tier' => $schema->string()->description('Tier name: good, better, or best')->required(),
            'production_rate_id' => $schema->integer()->description('Override production rate for this tier'),
            'coats' => $schema->integer()->description('Override coat count for this tier (e.g., 2 for Good, 3 for Best)'),
            'paint_product_id' => $schema->integer()->description('Override paint product for this tier'),
            'labor_hours' => $schema->number()->description('Explicit labor hours (skips auto-calc from rate/coats)'),
            'material_cost' => $schema->number()->description('Explicit material cost (skips auto-calc from paint product)'),
        ];
    }
}
