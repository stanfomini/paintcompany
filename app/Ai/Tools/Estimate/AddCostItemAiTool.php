<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddCostItemAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a cost item (material, rental, equipment, service) to an area or surface. Cost items flow into COGS and are included in margin calculations.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->addCostItem(
                estimate: $this->estimate,
                targetType: $request['target_type'],
                targetId: (int) $request['target_id'],
                name: $request['name'],
                costType: $request['cost_type'] ?? 'material',
                quantity: isset($request['quantity']) ? (float) $request['quantity'] : 1,
                unit: $request['unit'] ?? 'each',
                unitCost: isset($request['unit_cost']) ? (float) $request['unit_cost'] : 0,
                unitPrice: isset($request['unit_price']) ? (float) $request['unit_price'] : 0,
                tier: $request['tier'] ?? null,
                notes: $request['notes'] ?? null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'target_type' => $schema->string()->description('Target: area or surface')->required(),
            'target_id' => $schema->integer()->description('Area ID or Surface ID')->required(),
            'name' => $schema->string()->description('Item name, e.g., "Baseboard Material", "Sprayer Rental"')->required(),
            'cost_type' => $schema->string()->description('Type: material, rental, equipment, service, custom (default: material)'),
            'quantity' => $schema->number()->description('Quantity (default: 1)'),
            'unit' => $schema->string()->description('Unit: lnft, sqft, day, each, hour, gallon (default: each)'),
            'unit_cost' => $schema->number()->description('Vendor cost per unit (what we pay)'),
            'unit_price' => $schema->number()->description('Customer price per unit (what we charge)'),
            'tier' => $schema->string()->description('Optional tier: good, better, best (null = all tiers)'),
            'notes' => $schema->string()->description('Optional notes'),
        ];
    }
}
