<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListPaintProductsAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List available paint products filtered by scope, sheen, or quality tier. Returns product names, prices per gallon, coverage rates. Use this to find paint product IDs when setting paint.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->listPaintProducts(
            scopeType: $request['scope_type'] ?? null,
            sheen: $request['sheen'] ?? null,
            qualityTier: $request['quality_tier'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope_type' => $schema->string()->description('Filter by scope: interior, exterior, or both'),
            'sheen' => $schema->string()->description('Filter by sheen: flat, matte, eggshell, satin, semi-gloss, gloss'),
            'quality_tier' => $schema->string()->description('Filter by quality tier: good, better, best'),
        ];
    }
}
