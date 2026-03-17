<?php

namespace App\Mcp\Tools\Estimate;

use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AddAddonToAreaTool extends Tool
{
    protected string $name = 'add_addon_to_area';

    protected string $description = <<<'MARKDOWN'
        Add a fixed-price addon (nested area template) to an area. Addons are pre-priced items like closets, accent walls, or half baths. See the nested_area_templates resource for available options.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'area_id' => 'required|integer|exists:estimate_areas,id',
            'nested_template_id' => 'required|integer|exists:nested_area_templates,id',
            'quantity' => 'nullable|integer|min:1|max:50',
        ]);

        $result = $service->addAddonToArea(
            areaId: $data['area_id'],
            nestedTemplateId: $data['nested_template_id'],
            quantity: $data['quantity'] ?? 1,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'area_id' => $schema->integer()->description('The area ID to add the addon to')->required(),
            'nested_template_id' => $schema->integer()->description('The nested area template ID (see nested_area_templates resource)')->required(),
            'quantity' => $schema->integer()->description('Number of this addon (default: 1)'),
        ];
    }
}
