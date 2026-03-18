<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListCostItemsAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'List cost items (materials, rentals, equipment) on an area or surface.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->listCostItems(
                estimate: $this->estimate,
                targetType: $request['target_type'],
                targetId: (int) $request['target_id'],
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
        ];
    }
}
