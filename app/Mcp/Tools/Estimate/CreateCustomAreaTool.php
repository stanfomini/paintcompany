<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateCustomAreaTool extends Tool
{
    protected string $name = 'create_custom_area';

    protected string $description = <<<'MARKDOWN'
        Create a custom area with manual dimensions (no template). After creation, add surfaces using the add_surface_to_area tool. Wall sqft, ceiling sqft, and perimeter are auto-calculated from dimensions.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
            'name' => 'required|string|max:255',
            'scope_type' => 'required|string|in:interior,exterior',
            'length' => 'required|numeric|min:0.1',
            'width' => 'required|numeric|min:0.1',
            'height' => 'required|numeric|min:0.1',
            'wall_count' => 'nullable|integer|min:1|max:20',
            'substrate_id' => 'nullable|integer|exists:substrates,id',
        ]);

        $estimate = Estimate::findOrFail($data['estimate_id']);

        $result = $service->createCustomArea(
            estimate: $estimate,
            name: $data['name'],
            scopeType: $data['scope_type'],
            length: (float) $data['length'],
            width: (float) $data['width'],
            height: (float) $data['height'],
            wallCount: $data['wall_count'] ?? 4,
            substrateId: $data['substrate_id'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to add the area to')->required(),
            'name' => $schema->string()->description('Area name (e.g., "Master Bedroom", "Front Porch")')->required(),
            'scope_type' => $schema->string()->description('Scope type: interior or exterior')->required(),
            'length' => $schema->number()->description('Room length in feet')->required(),
            'width' => $schema->number()->description('Room width in feet')->required(),
            'height' => $schema->number()->description('Wall height in feet (typically 8-10)')->required(),
            'wall_count' => $schema->integer()->description('Number of walls (default: 4)'),
            'substrate_id' => $schema->integer()->description('Optional substrate/surface condition ID'),
        ];
    }
}
