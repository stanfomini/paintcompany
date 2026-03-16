<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GenerateMissingFeaturePromptAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Generate a Claude Code prompt for building a missing workflow capability. Use this when the user requests something that doesn\'t exist yet in the available triggers/actions/conditions/waits.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        return $service->generateMissingFeaturePrompt(
            featureName: $request['feature_name'],
            featureType: $request['feature_type'],
            description: $request['description'],
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'feature_name' => $schema->string()->description('Name of the missing feature (e.g., "Send Slack Message")')->required(),
            'feature_type' => $schema->string()->description('Type: trigger, action, condition, or wait')->required(),
            'description' => $schema->string()->description('What the feature should do')->required(),
        ];
    }
}
