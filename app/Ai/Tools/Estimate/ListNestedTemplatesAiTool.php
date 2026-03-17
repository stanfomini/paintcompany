<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListNestedTemplatesAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List fixed-price add-on items like closets, repairs, wallpaper removal, warranties. Use when user mentions items that aren\'t full rooms.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->listNestedTemplates(
            scopeType: $request['scope_type'] ?? null,
            category: $request['category'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope_type' => $schema->string()->description('Filter by scope: interior, exterior, or both'),
            'category' => $schema->string()->description('Filter by category (e.g., closet, repair, warranty)'),
        ];
    }
}
