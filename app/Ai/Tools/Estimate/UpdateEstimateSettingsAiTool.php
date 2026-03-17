<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateEstimateSettingsAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Update estimate-level settings: title, project type, tax rate, deposit percent, margin discount, notes.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->updateEstimateSettings(
                estimate: $this->estimate->fresh(),
                params: array_filter([
                    'title' => $request['title'] ?? null,
                    'project_type' => $request['project_type'] ?? null,
                    'tax_rate' => $request['tax_rate'] ?? null,
                    'deposit_percent' => $request['deposit_percent'] ?? null,
                    'margin_discount_percent' => $request['margin_discount_percent'] ?? null,
                    'notes' => $request['notes'] ?? null,
                ], fn ($v) => $v !== null),
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Estimate title/name'),
            'project_type' => $schema->string()->description('Project type: interior, exterior, or both'),
            'tax_rate' => $schema->number()->description('Tax rate percentage (e.g., 8.31)'),
            'deposit_percent' => $schema->number()->description('Deposit percentage (e.g., 50)'),
            'margin_discount_percent' => $schema->number()->description('Margin discount to lower customer price (e.g., 5 for 5% discount)'),
            'notes' => $schema->string()->description('Internal notes for the estimate'),
        ];
    }
}
