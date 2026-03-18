<?php

namespace App\Ai\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateTemplateAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Create a new room/area template. Use when no existing template matches. Supports 4 categories: room (with S/M/L sizes), surface (single surface type), item (fixed/per_sqft pricing), addon (fixed price). The template is permanent and reusable.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->createTemplate(
                name: $request['name'],
                scopeType: $request['scope_type'] ?? 'interior',
                category: $request['category'] ?? 'room',
                pricingMethod: $request['pricing_method'] ?? 'production_rate',
                sizes: isset($request['sizes']) ? (array) $request['sizes'] : null,
                surfaces: isset($request['surfaces']) ? (array) $request['surfaces'] : null,
                description: $request['description'] ?? null,
                basePrice: isset($request['base_price']) ? (float) $request['base_price'] : null,
                perSqftRate: isset($request['per_sqft_rate']) ? (float) $request['per_sqft_rate'] : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Template name, e.g., "Sunroom"')->required(),
            'scope_type' => $schema->string()->description('Scope: interior or exterior')->required(),
            'category' => $schema->string()->description('Category: room, surface, item, addon (default: room)'),
            'pricing_method' => $schema->string()->description('Pricing: production_rate, fixed, per_sqft (default: production_rate)'),
            'sizes' => $schema->string()->description('JSON object with S/M/L dimensions, e.g., {"small": {"length": 10, "width": 10, "height": 8}, "medium": {"length": 14, "width": 12, "height": 8}, "large": {"length": 18, "width": 14, "height": 9}}'),
            'surfaces' => $schema->string()->description('JSON array of surfaces, e.g., [{"name": "Walls", "surface_type": "walls", "production_rate_id": 1}, {"name": "Ceiling", "surface_type": "ceiling", "production_rate_id": 3}]'),
            'description' => $schema->string()->description('Template description'),
            'base_price' => $schema->number()->description('Fixed base price (for fixed pricing method)'),
            'per_sqft_rate' => $schema->number()->description('Per-sqft rate (for per_sqft pricing method)'),
        ];
    }
}
