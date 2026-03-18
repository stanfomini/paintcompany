<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetTierPricingAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Configure Good/Better/Best tier pricing by setting paint products per tier. This ONLY changes paint/material costs — it does NOT change coats or labor hours. To change coats per tier, use update_surface_tier_overrides_ai_tool instead. Call once per tier.';
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

            $result = $service->setTierPricing(
                estimate: $this->estimate->fresh(),
                tier: $request['tier'],
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
            'tier' => $schema->string()->description('Tier name: good, better, or best')->required(),
            'paint_product_id' => $schema->integer()->description('Default paint product ID for this tier'),
            'walls_paint_id' => $schema->integer()->description('Paint product ID for walls in this tier'),
            'ceiling_paint_id' => $schema->integer()->description('Paint product ID for ceilings in this tier'),
            'trim_paint_id' => $schema->integer()->description('Paint product ID for trim in this tier'),
            'doors_paint_id' => $schema->integer()->description('Paint product ID for doors in this tier'),
        ];
    }
}
