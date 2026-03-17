<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateAreaFromTemplateAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Create a new area from a template. Auto-creates surfaces with production rates and calculates pricing. Use this when a matching template exists (check with search_templates first).';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->createAreaFromTemplate(
                estimate: $this->estimate->fresh(),
                templateId: (int) $request['template_id'],
                size: $request['size'] ?? 'medium',
                coats: isset($request['coats']) ? (int) $request['coats'] : 2,
                substrateId: isset($request['substrate_id']) ? (int) $request['substrate_id'] : null,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->integer()->description('Template ID from search_templates results')->required(),
            'size' => $schema->string()->description('Size: small, medium, or large (default: medium)'),
            'coats' => $schema->integer()->description('Number of coats (default: 2)'),
            'substrate_id' => $schema->integer()->description('Optional substrate ID for surface condition'),
        ];
    }
}
