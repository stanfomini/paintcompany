<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetPricingConfigAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Get company pricing settings by scope: hourly wages, effective rates, margins, cleanup ratios, sundries rates. Use to understand how prices are calculated.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->getPricingConfig());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
