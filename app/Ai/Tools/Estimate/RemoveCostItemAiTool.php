<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class RemoveCostItemAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Remove a cost item from an area or surface.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->removeCostItem(
                estimate: $this->estimate,
                costItemId: (int) $request['cost_item_id'],
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cost_item_id' => $schema->integer()->description('Cost item ID to remove')->required(),
        ];
    }
}
