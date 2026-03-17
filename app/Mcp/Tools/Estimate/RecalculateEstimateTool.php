<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class RecalculateEstimateTool extends Tool
{
    protected string $name = 'recalculate_estimate';

    protected string $description = <<<'MARKDOWN'
        Recalculate all totals on the estimate including subtotal, tax, total, deposit, and balance due. Call after bulk changes.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
        ]);

        $estimate = Estimate::findOrFail($data['estimate_id']);
        $result = $service->recalculate($estimate);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to recalculate')->required(),
        ];
    }
}
