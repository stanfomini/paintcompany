<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CreateAreaFromTemplateTool extends Tool
{
    protected string $name = 'create_area_from_template';

    protected string $description = <<<'MARKDOWN'
        Create a new area (room) from a template with preset dimensions and surfaces. Templates have size options (small, medium, large) that set room dimensions. Surfaces are auto-generated from the template definition.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
            'template_id' => 'required|integer|exists:estimate_templates,id',
            'size' => 'nullable|string|in:small,medium,large',
            'coats' => 'nullable|integer|min:1|max:5',
            'substrate_id' => 'nullable|integer|exists:substrates,id',
        ]);

        $estimate = Estimate::findOrFail($data['estimate_id']);

        $result = $service->createAreaFromTemplate(
            estimate: $estimate,
            templateId: $data['template_id'],
            size: $data['size'] ?? 'medium',
            coats: $data['coats'] ?? 2,
            substrateId: $data['substrate_id'] ?? null,
        );

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to add the area to')->required(),
            'template_id' => $schema->integer()->description('The template ID to create the area from (see estimate_templates resource)')->required(),
            'size' => $schema->string()->description('Size option: small, medium, or large (default: medium)'),
            'coats' => $schema->integer()->description('Number of paint coats (default: 2)'),
            'substrate_id' => $schema->integer()->description('Optional substrate/surface condition ID (see substrates resource)'),
        ];
    }
}
