<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class WriteEstimateSummaryAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Write a professional markdown summary for the customer proposal. This appears on the public proposal page. Use clear, professional language describing the scope of work.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $result = $service->updateAiSummary(
                estimate: $this->estimate->fresh(),
                summary: $request['summary'],
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->description('Markdown-formatted proposal summary for the customer. Include scope overview, key details, and professional tone.')->required(),
        ];
    }
}
