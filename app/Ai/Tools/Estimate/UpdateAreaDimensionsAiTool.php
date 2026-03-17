<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateAreaDimensionsAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Change an area\'s dimensions. Auto-recalculates all surface quantities and reprices. Use when user says actually that room is 14x16.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->updateAreaDimensions(
                areaId: (int) $request['area_id'],
                length: isset($request['length']) ? (float) $request['length'] : null,
                width: isset($request['width']) ? (float) $request['width'] : null,
                height: isset($request['height']) ? (float) $request['height'] : null,
                wallCount: isset($request['wall_count']) ? (int) $request['wall_count'] : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('ID of the area to update')->required(),
            'length' => $schema->number()->description('New length in feet'),
            'width' => $schema->number()->description('New width in feet'),
            'height' => $schema->number()->description('New wall height in feet'),
            'wall_count' => $schema->integer()->description('New number of walls'),
        ];
    }
}
