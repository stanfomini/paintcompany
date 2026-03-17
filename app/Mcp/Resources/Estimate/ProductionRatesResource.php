<?php

namespace App\Mcp\Resources\Estimate;

use App\Services\EstimateAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ProductionRatesResource extends Resource
{
    protected string $name = 'production_rates';

    protected string $uri = 'estimate://production-rates';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All painting production rates organized by scope (interior/exterior) and surface category. Each rate defines sqft/hr, lnft/hr, or hours/unit for pricing calculations. Use rate IDs with the add_surface_to_area tool.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        return Response::json($service->listProductionRates());
    }
}
