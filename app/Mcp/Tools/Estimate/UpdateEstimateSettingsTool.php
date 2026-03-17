<?php

namespace App\Mcp\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class UpdateEstimateSettingsTool extends Tool
{
    protected string $name = 'update_estimate_settings';

    protected string $description = <<<'MARKDOWN'
        Update estimate-level settings such as title, project type, notes, valid_until date, tax rate, deposit percent, or margin discount.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        $data = $request->validate([
            'estimate_id' => 'required|integer|exists:estimates,id',
            'title' => 'nullable|string|max:255',
            'project_type' => 'nullable|string|in:interior,exterior,both',
            'notes' => 'nullable|string',
            'valid_until' => 'nullable|date',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'deposit_percent' => 'nullable|numeric|min:0|max:100',
            'margin_discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        $estimate = Estimate::findOrFail($data['estimate_id']);

        $params = collect($data)->except('estimate_id')->filter(fn ($v) => $v !== null)->toArray();
        $result = $service->updateEstimateSettings($estimate, $params);

        return Response::json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'estimate_id' => $schema->integer()->description('The estimate ID to update')->required(),
            'title' => $schema->string()->description('Estimate title'),
            'project_type' => $schema->string()->description('Project type: interior, exterior, or both'),
            'notes' => $schema->string()->description('Internal notes for the estimate'),
            'valid_until' => $schema->string()->description('Expiration date (YYYY-MM-DD format)'),
            'tax_rate' => $schema->number()->description('Tax rate percentage (0-100)'),
            'deposit_percent' => $schema->number()->description('Deposit percentage (0-100)'),
            'margin_discount_percent' => $schema->number()->description('Margin discount percentage (0-100)'),
        ];
    }
}
