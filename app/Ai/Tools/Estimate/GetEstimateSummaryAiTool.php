<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetEstimateSummaryAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Get a formatted pricing summary with per-area breakdown and grand totals. Optionally for a specific tier. Call when the user asks what\'s the total or show me the breakdown.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->getEstimateSummary(
            estimate: $this->estimate->fresh(),
            tier: $request['tier'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tier' => $schema->string()->description('Optional tier: good, better, or best. Omit for default pricing.'),
        ];
    }
}
