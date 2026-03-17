<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetEstimateStateTool extends Tool
{
    protected string $name = 'get_estimate_state';

    protected string $description = <<<'MARKDOWN'
        Get the full current state of an estimate including all areas, surfaces, addons, dimensions, and pricing totals.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
        ]);

        $estimate = Estimate::findOrFail($data['estimate_id']);
        $result = $service->getEstimateState($estimate);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to inspect')->required(),
        ];
    }
}
