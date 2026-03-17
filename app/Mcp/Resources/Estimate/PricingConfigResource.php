<?php

namespace App\Mcp\Resources\Estimate;

use App\Services\EstimateAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class PricingConfigResource extends Resource
{
    protected string $name = 'pricing_config';

    protected string $uri = 'estimate://pricing-config';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        Company pricing configuration by scope (interior/exterior) including hourly wage, effective rate, payroll burden, margin percentage, cleanup ratio, sundries cost, and tax rates. Shows how the PBP pricing formula is applied.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        return Response::json($service->getPricingConfig());
    }
}
