<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListProductionRatesAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List painting production rates by scope and category. Returns rate values (sqft/hr, lnft/hr, hours/unit) with names. Use this to find the right production rate when adding surfaces.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->listProductionRates(
            scopeType: $request['scope_type'] ?? null,
            category: $request['category'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope_type' => $schema->string()->description('Filter by scope: interior or exterior'),
            'category' => $schema->string()->description('Filter by surface_category. Interior: walls|ceiling|baseboard|trim|doors|windows|prep. Exterior: body|eaves|fascia|trim|exterior_doors|windows|wood_replacement|prep'),
        ];
    }
}
