<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListDynamicValuesAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'List available dynamic value fields for a given trigger class. These are {{prefix.key}} variables you can use in action configs (e.g., {{contact.email}}, {{deal.value}}). Call this after choosing a trigger to know what data is available.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);
        $fields = $service->listDynamicValues($request['trigger_class']);

        if (empty($fields)) {
            return 'No dynamic values available for this trigger class.';
        }

        return json_encode($fields);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'trigger_class' => $schema->string()->description('Fully qualified trigger class name (e.g., App\\Automation\\Triggers\\ContactCreated)')->required(),
        ];
    }
}
