<?php

namespace App\Mcp\Resources\Estimate;

use App\Services\EstimateAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class SubstrateConditionsResource extends Resource
{
    protected string $name = 'substrate_conditions';

    protected string $uri = 'estimate://substrates';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All active substrate conditions (surface conditions like previously painted, new drywall, peeling paint) with prep time, paint time, and material multipliers. Use substrate IDs with create_area_from_template or create_custom_area.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        return Response::json($service->listSubstrates());
    }
}
