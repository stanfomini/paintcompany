<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\Workflow\AvailableActionsResource;
use App\Mcp\Resources\Workflow\AvailableConditionsResource;
use App\Mcp\Resources\Workflow\AvailableTriggersResource;
use App\Mcp\Resources\Workflow\AvailableWaitsResource;
use App\Mcp\Tools\Workflow\AddWorkflowNodeTool;
use App\Mcp\Tools\Workflow\ConfigureWorkflowNodeTool;
use App\Mcp\Tools\Workflow\ConnectWorkflowNodesTool;
use App\Mcp\Tools\Workflow\GenerateMissingFeaturePromptTool;
use App\Mcp\Tools\Workflow\GetWorkflowStateTool;
use App\Mcp\Tools\Workflow\RemoveWorkflowEdgeTool;
use App\Mcp\Tools\Workflow\RemoveWorkflowNodeTool;
use Laravel\Mcp\Server;

class WorkflowBuilderServer extends Server
{
    protected string $name = 'Workflow Builder';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Build and modify automation workflows for a painting company CRM.

        Workflows are directed graphs: a single Trigger node leads to Action, Condition (if_else),
        Wait, Goal, and GoTo nodes connected by edges. Use the available tools to add nodes,
        connect them, and configure their settings.

        Read the available_triggers, available_actions, available_conditions, and available_waits
        resources to discover what node types you can use and their config schemas.

        Use dynamic values like {{contact.email}} or {{deal.value}} in action configs to reference
        data from the trigger context.
    MARKDOWN;

    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        AddWorkflowNodeTool::class,
        ConnectWorkflowNodesTool::class,
        ConfigureWorkflowNodeTool::class,
        RemoveWorkflowNodeTool::class,
        RemoveWorkflowEdgeTool::class,
        GetWorkflowStateTool::class,
        GenerateMissingFeaturePromptTool::class,
    ];

    /** @var array<int, class-string<\Laravel\Mcp\Server\Resource>> */
    protected array $resources = [
        AvailableTriggersResource::class,
        AvailableActionsResource::class,
        AvailableConditionsResource::class,
        AvailableWaitsResource::class,
    ];
}
