<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListSubstratesAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List surface conditions (substrates) that affect prep and paint time. Returns multipliers. Use when the user mentions surface conditions like peeling paint or new drywall.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        return json_encode($service->listSubstrates(
            scopeType: $request['scope_type'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope_type' => $schema->string()->description('Filter by scope: interior, exterior, or both'),
        ];
    }
}
