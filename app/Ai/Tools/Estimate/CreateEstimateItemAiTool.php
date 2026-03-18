<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateEstimateItemAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a line item on the estimate for equipment rental, material purchases, subcontractor work, or other charges not tied to a specific area/surface.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->createEstimateItem(
                estimate: $this->estimate->fresh(),
                description: $request['description'],
                quantity: (float) $request['quantity'],
                unit: $request['unit'] ?? 'each',
                unitPrice: (float) $request['unit_price'],
                cost: isset($request['cost']) ? (float) $request['cost'] : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()->description('Item description, e.g., "Sprayer rental (2 days)"')->required(),
            'quantity' => $schema->number()->description('Quantity (e.g., 2 for 2 days)')->required(),
            'unit' => $schema->string()->description('Unit: each, day, hour, gallon, lnft, sqft (default: each)'),
            'unit_price' => $schema->number()->description('Customer price per unit')->required(),
            'cost' => $schema->number()->description('Vendor/internal cost per unit (optional, for profit tracking)'),
        ];
    }
}
