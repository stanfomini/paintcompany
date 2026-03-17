<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetEstimatePaintConfigAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Set paint product(s) for the whole estimate. Single mode = one paint for everything. Category mode = different paint per surface type.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $categoryOverrides = null;
            if ($request['mode'] === 'category') {
                $categoryOverrides = array_filter([
                    'walls' => $request['walls_paint_id'] ?? null,
                    'ceiling' => $request['ceiling_paint_id'] ?? null,
                    'trim' => $request['trim_paint_id'] ?? null,
                    'doors' => $request['doors_paint_id'] ?? null,
                ], fn ($v) => $v !== null);
            }

            $result = $service->setEstimatePaintConfig(
                estimate: $this->estimate->fresh(),
                mode: $request['mode'],
                paintProductId: isset($request['paint_product_id']) ? (int) $request['paint_product_id'] : null,
                categoryOverrides: $categoryOverrides,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->description('Paint mode: single (one paint for all) or category (different per surface type)')->required(),
            'paint_product_id' => $schema->integer()->description('Paint product ID for single mode, or default fallback for category mode'),
            'walls_paint_id' => $schema->integer()->description('Paint product ID for walls (category mode only)'),
            'ceiling_paint_id' => $schema->integer()->description('Paint product ID for ceilings (category mode only)'),
            'trim_paint_id' => $schema->integer()->description('Paint product ID for trim/baseboards (category mode only)'),
            'doors_paint_id' => $schema->integer()->description('Paint product ID for doors (category mode only)'),
        ];
    }
}
