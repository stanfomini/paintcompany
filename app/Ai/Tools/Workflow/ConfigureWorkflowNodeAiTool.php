<?php

namespace App\Ai\Tools\Workflow;

use App\Services\WorkflowAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ConfigureWorkflowNodeAiTool implements Tool
{
    public function description(): Stringable|string
    {
        return 'Update a node\'s configuration. Config is merged with existing values. Use dynamic values like {{contact.email}} in string config values. Also optionally update the node label.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(WorkflowAiService::class);

        $config = $request['config'];
        if (is_string($config)) {
            $config = json_decode($config, true) ?? [];
        }

        $result = $service->configureNode(
            nodeId: (int) $request['node_id'],
            config: $config,
            label: $request['label'] ?? null,
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'node_id' => $schema->integer()->description('The node ID to configure')->required(),
            'config' => $schema->object()->description('Configuration key-value pairs')->required(),
            'label' => $schema->string()->description('Optional custom label'),
        ];
    }
}
