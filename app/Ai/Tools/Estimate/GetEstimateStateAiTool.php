<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetEstimateStateAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Get the current state of this estimate: all areas with their surfaces, pricing, paint config, tier config, totals. Call this first to understand what exists.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->getEstimateState($this->estimate->fresh()));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
