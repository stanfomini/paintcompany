<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ExecuteEstimatePlanAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Execute a batch estimate plan in a single atomic transaction. Creates AND updates areas, surfaces, addons, cost items, line items, paint, and tier pricing — all at once. Use "areas" to create new areas. Use "update_areas" to modify existing areas (rename, resize, update/add/remove surfaces). Use "apply_tiers_to_existing: true" with "tier_config" to add tier pricing to all existing surfaces. For simple single-field changes on one surface, use individual tools instead.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $plan = [];

            if (isset($request['estimate_settings'])) {
                $plan['estimate_settings'] = $request['estimate_settings'];
            }

            if (isset($request['paint_config'])) {
                $plan['paint_config'] = $request['paint_config'];
            }

            if (isset($request['tier_config'])) {
                $plan['tier_config'] = $request['tier_config'];
            }

            if (isset($request['create_first'])) {
                $plan['create_first'] = $request['create_first'];
            }

            if (isset($request['areas'])) {
                $plan['areas'] = $request['areas'];
            }

            if (isset($request['line_items'])) {
                $plan['line_items'] = $request['line_items'];
            }

            if (isset($request['update_areas'])) {
                $plan['update_areas'] = $request['update_areas'];
            }

            if (isset($request['apply_tiers_to_existing'])) {
                $plan['apply_tiers_to_existing'] = $request['apply_tiers_to_existing'];
            }

            $result = $service->executePlan(
                estimate: $this->estimate->fresh(),
                plan: $plan,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return json_encode([
                'success' => false,
                'error' => $e->getMessage(),
                'hint' => 'The entire plan was rolled back. Fix the error and try again.',
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_settings' => $schema->object()->description('Optional. {title: string, project_type: "interior"|"exterior"|"both", notes: string}'),
            'paint_config' => $schema->object()->description('Optional. {mode: "single"|"category", paint_product_id: int (for single), category_overrides: {walls: int, ceiling: int, trim: int, doors: int} (for category)}'),
            'tier_config' => $schema->object()->description('Optional. Good/Better/Best tier config. {good: {paint_product_id: int, default_coats: int}, better: {...}, best: {...}}. Tier overrides auto-applied to all surfaces.'),
            'create_first' => $schema->object()->description('Optional. {production_rates: [{ref: string, name: string, scope: "interior"|"exterior", surface_category: string, production_rate: number, rate_type: string, unit_type: string, default_coats: int, description: string}]}. Use ref to reference in surfaces as "ref:key".'),
            'areas' => $schema->array()->description('NEW areas to create. Each: {source: "template"|"custom", template_id: int, size: "small"|"medium"|"large", name: string, scope_type: string, coats: int, substrate_id: int, dimensions: {length, width, height, wall_count}, surfaces: [{name, surface_category, production_rate_id, coats, quantity_override}], addons: [{nested_template_id, quantity}], cost_items: [{name, cost_type, quantity, unit, unit_cost}], package_tiers: ["good","better","best"]}'),
            'update_areas' => $schema->array()->description('EXISTING areas to modify. Each: {area_id: int (required), name: string, dimensions: {length, width, height, wall_count}, substrate_id: int, update_surfaces: [{surface_id: int, name: string, coats: int, production_rate_id: int, quantity_override: number, paint_product_id: int, surface_category: string, application_method: string}], add_surfaces: [{name, surface_category, production_rate_id, coats, quantity_override}], remove_surface_ids: [int], addons: [{nested_template_id, quantity}], cost_items: [{name, cost_type, quantity, unit, unit_cost}], package_tiers: ["good","better","best"]}'),
            'apply_tiers_to_existing' => $schema->boolean()->description('When true + tier_config provided, applies tier overrides to ALL existing surfaces that don\'t already have them. Use when adding tiers to a previously-built estimate.'),
            'line_items' => $schema->array()->description('Standalone line items (no margin). Each: {description: string, quantity: number, unit: string, unit_price: number, cost: number}'),
        ];
    }
}
