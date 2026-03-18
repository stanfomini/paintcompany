<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateNestedTemplateAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Create a nested area template (closets, half baths, prep work) with optional real surfaces for accurate labor/cost calculation. The template is permanent and reusable with add_addon_to_area.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->createNestedTemplate(
                name: $request['name'],
                scopeType: $request['scope_type'] ?? 'interior',
                category: $request['category'] ?? 'other',
                fixedPrice: isset($request['fixed_price']) ? (float) $request['fixed_price'] : null,
                estimatedHours: isset($request['estimated_hours']) ? (float) $request['estimated_hours'] : null,
                materialCost: isset($request['material_cost']) ? (float) $request['material_cost'] : null,
                description: $request['description'] ?? null,
                surfaces: isset($request['surfaces']) ? (array) $request['surfaces'] : null,
                includesWalls: (bool) ($request['includes_walls'] ?? false),
                includesCeiling: (bool) ($request['includes_ceiling'] ?? false),
                includesTrim: (bool) ($request['includes_trim'] ?? false),
                includesDoor: (bool) ($request['includes_door'] ?? false),
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Template name, e.g., "Large Walk-in Closet"')->required(),
            'scope_type' => $schema->string()->description('Scope: interior, exterior, or both (default: interior)'),
            'category' => $schema->string()->description('Category: closet, bathroom, utility, other (default: other)'),
            'fixed_price' => $schema->number()->description('Fixed price (used as fallback if no surfaces defined)'),
            'estimated_hours' => $schema->number()->description('Estimated labor hours'),
            'material_cost' => $schema->number()->description('Estimated material cost'),
            'description' => $schema->string()->description('Description of what this template covers'),
            'surfaces' => $schema->string()->description('JSON array of surfaces with production_rate_ids, e.g., [{"name": "Walls", "surface_type": "walls", "production_rate_id": 1, "calculation_method": "ratio", "calculation_value": 1.0}]'),
            'includes_walls' => $schema->boolean()->description('Includes wall painting'),
            'includes_ceiling' => $schema->boolean()->description('Includes ceiling painting'),
            'includes_trim' => $schema->boolean()->description('Includes trim painting'),
            'includes_door' => $schema->boolean()->description('Includes door painting'),
        ];
    }
}
