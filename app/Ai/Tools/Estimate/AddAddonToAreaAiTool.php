<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class AddAddonToAreaAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Add a fixed-price addon (closet, repair, warranty) to an area.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->addAddonToArea(
                areaId: (int) $request['area_id'],
                nestedTemplateId: (int) $request['nested_template_id'],
                quantity: isset($request['quantity']) ? (int) $request['quantity'] : 1,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('ID of the area to add the addon to')->required(),
            'nested_template_id' => $schema->integer()->description('Nested area template ID from list_nested_templates')->required(),
            'quantity' => $schema->integer()->description('Number of this addon (default: 1)'),
        ];
    }
}
