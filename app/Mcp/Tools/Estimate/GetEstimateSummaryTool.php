<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetEstimateSummaryTool extends Tool
{
    protected string $name = 'get_estimate_summary';

    protected string $description = <<<'MARKDOWN'
        Get a human-readable estimate summary with per-area COGS and customer price breakdown. Optionally specify a tier (good, better, best) for tier-specific pricing.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
            'tier' => 'nullable|string|in:good,better,best',
        ]);

        $estimate = Estimate::findOrFail($data['estimate_id']);
        $result = $service->getEstimateSummary($estimate, $data['tier'] ?? null);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to summarize')->required(),
            'tier' => $schema->string()->description('Optional pricing tier: good, better, or best'),
        ];
    }
}
