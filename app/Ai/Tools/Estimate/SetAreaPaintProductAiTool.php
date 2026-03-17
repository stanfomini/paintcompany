<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetAreaPaintProductAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Override paint product for a specific area.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $categoryOverrides = array_filter([
                'walls' => $request['walls_paint_id'] ?? null,
                'ceiling' => $request['ceiling_paint_id'] ?? null,
                'trim' => $request['trim_paint_id'] ?? null,
                'doors' => $request['doors_paint_id'] ?? null,
            ], fn ($v) => $v !== null);

            $result = $service->setAreaPaintProduct(
                areaId: (int) $request['area_id'],
                paintProductId: isset($request['paint_product_id']) ? (int) $request['paint_product_id'] : null,
                categoryOverrides: ! empty($categoryOverrides) ? $categoryOverrides : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('ID of the area to set paint for')->required(),
            'paint_product_id' => $schema->integer()->description('Paint product ID for all surfaces in this area'),
            'walls_paint_id' => $schema->integer()->description('Paint product ID for walls only'),
            'ceiling_paint_id' => $schema->integer()->description('Paint product ID for ceilings only'),
            'trim_paint_id' => $schema->integer()->description('Paint product ID for trim/baseboards only'),
            'doors_paint_id' => $schema->integer()->description('Paint product ID for doors only'),
        ];
    }
}
