<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchTemplatesAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Search for room/area templates by name or keywords. Returns matching templates with available sizes (S/M/L), surface configurations, and production rates. Call this before creating areas to check if a reusable template exists.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->searchTemplates(
            query: $request['query'],
            scopeType: $request['scope_type'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search query for template name or keywords (e.g., "living room", "bedroom", "exterior")')->required(),
            'scope_type' => $schema->string()->description('Filter by scope: interior or exterior'),
        ];
    }
}
