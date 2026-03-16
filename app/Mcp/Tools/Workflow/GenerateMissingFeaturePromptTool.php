<?php

namespace App\Mcp\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GenerateMissingFeaturePromptTool extends Tool
{
    protected string $name = 'generate_missing_feature_prompt';

    protected string $description = <<<'MARKDOWN'
        Generate a Claude Code prompt for building a missing workflow capability (trigger, action, condition, or wait) that doesn't exist yet in the automation system.
    MARKDOWN;

    public function handle(Request $request, WorkflowAiService $service): Response
    {
        $data = $request->validate([
            'feature_name' => 'required|string|max:255',
            'feature_type' => 'required|string|in:trigger,action,condition,wait',
            'description' => 'required|string|max:1000',
        ]);

        $prompt = $service->generateMissingFeaturePrompt(
            featureName: $data['feature_name'],
            featureType: $data['feature_type'],
            description: $data['description'],
        );

        return Response::text($prompt);
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
