<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateCustomAreaAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a new area with custom dimensions. Returns the area with calculated wall_sqft, ceiling_sqft, perimeter_lnft but no surfaces — you must add surfaces one by one with add_surface_to_area.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->createCustomArea(
                estimate: $this->estimate->fresh(),
                name: $request['name'],
                scopeType: $request['scope_type'],
                length: (float) $request['length'],
                width: (float) $request['width'],
                height: (float) $request['height'],
                wallCount: isset($request['wall_count']) ? (int) $request['wall_count'] : 4,
                substrateId: isset($request['substrate_id']) ? (int) $request['substrate_id'] : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Area name (e.g., "Master Bedroom", "Front Porch")')->required(),
            'scope_type' => $schema->string()->description('Scope type: interior or exterior')->required(),
            'length' => $schema->number()->description('Room length in feet')->required(),
            'width' => $schema->number()->description('Room width in feet')->required(),
            'height' => $schema->number()->description('Wall/ceiling height in feet (default: 8)')->required(),
            'wall_count' => $schema->integer()->description('Number of walls (default: 4)'),
            'substrate_id' => $schema->integer()->description('Optional substrate ID for surface condition'),
        ];
    }
}
