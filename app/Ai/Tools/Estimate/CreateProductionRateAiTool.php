<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateProductionRateAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Create a new production rate. Use when no existing rate matches the work being estimated. Supports all rate types: sqft_hr, lnft_hr, units_hr, hours_per_unit, cost_per_lnft. The rate is permanent and reusable across estimates.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->createProductionRate(
                name: $request['name'],
                scope: $request['scope'],
                surfaceCategory: $request['surface_category'],
                productionRate: (float) $request['production_rate'],
                rateType: $request['rate_type'] ?? 'sqft_hr',
                unitType: $request['unit_type'] ?? 'sqft',
                defaultCoats: isset($request['default_coats']) ? (int) $request['default_coats'] : 2,
                description: $request['description'] ?? null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Rate name, e.g., "Sunroom Walls - Brush/Roll"')->required(),
            'scope' => $schema->string()->description('Scope: interior or exterior')->required(),
            'surface_category' => $schema->string()->description('Surface category. Interior: walls|ceiling|baseboard|trim|doors|windows|prep. Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep. Do NOT use siding, crown, molding, or other.')->required(),
            'production_rate' => $schema->number()->description('Rate value (e.g., 200 for 200 sqft/hr)')->required(),
            'rate_type' => $schema->string()->description('Rate type: sqft_hr, lnft_hr, units_hr, hours_per_unit, cost_per_lnft (default: sqft_hr)'),
            'unit_type' => $schema->string()->description('Unit: sqft, lnft, unit (default: sqft)'),
            'default_coats' => $schema->integer()->description('Default coats (default: 2)'),
            'description' => $schema->string()->description('Description of when to use this rate'),
        ];
    }
}
