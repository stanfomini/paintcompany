<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Workflow\AddWorkflowNodeAiTool;
use App\Ai\Tools\Workflow\ConfigureWorkflowNodeAiTool;
use App\Ai\Tools\Workflow\ConnectWorkflowNodesAiTool;
use App\Ai\Tools\Workflow\GenerateMissingFeaturePromptAiTool;
use App\Ai\Tools\Workflow\GetWorkflowStateAiTool;
use App\Ai\Tools\Workflow\ListAvailableActionsAiTool;
use App\Ai\Tools\Workflow\ListAvailableConditionsAiTool;
use App\Ai\Tools\Workflow\ListAvailableTriggersAiTool;
use App\Ai\Tools\Workflow\ListAvailableWaitsAiTool;
use App\Ai\Tools\Workflow\ListDynamicValuesAiTool;
use App\Ai\Tools\Workflow\RemoveWorkflowEdgeAiTool;
use App\Ai\Tools\Workflow\RemoveWorkflowNodeAiTool;
use App\Models\CompanySetting;
use App\Models\Workflow;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::xAI)]
#[MaxSteps(15)]
#[Timeout(120)]
class WorkflowBuilderAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @var array<int, array{role: string, content: string}> */
    protected array $conversationHistory = [];

    public function __construct(
        public Workflow $workflow,
    ) {}

    public function model(): string
    {
        return config('ai.providers.xai.model', 'grok-4-0709');
    }

    /**
     * Set conversation history from session.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function withHistory(array $history): static
    {
        $this->conversationHistory = $history;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $companyName = CompanySetting::getInstance()->company_name ?? 'the company';

        return <<<INSTRUCTIONS
        You are a workflow builder assistant for {$companyName}.
        You help users create and modify automation workflows by directly manipulating
        nodes and edges on a visual canvas.

        ## How Workflows Work
        - A workflow is a directed graph: Trigger → Actions/Conditions/Waits
        - Every workflow starts with exactly ONE trigger node
        - Node types: trigger, action, if_else (condition), wait, goal, goto
        - Edges connect nodes. if_else nodes have "true" and "false" branches
        - Dynamic values like {{contact.email}} can be used in action configs

        ## Your Process
        1. When building a new workflow, FIRST call list_available_triggers to see what triggers exist
        2. After choosing a trigger, call list_dynamic_values with the trigger class to see available data
        3. Call list_available_actions, list_available_conditions, list_available_waits as needed
        4. Add nodes, connect them, configure them with appropriate config values
        5. Call get_workflow_state at the end to verify the result

        ## Positioning Convention
        - Triggers: y=40, centered at x=250
        - Stack nodes 160px apart vertically (y += 160 for each level)
        - For if/else branches: true branch at x=100, false branch at x=400

        ## When Something Is Missing
        If the user requests a capability that doesn't exist in the available triggers/actions:
        1. Explain what's missing and why
        2. Suggest the closest alternative from what IS available
        3. Offer to generate a Claude Code prompt for building the missing feature

        ## Rules
        - Always start with a trigger if the workflow is empty
        - Connect nodes in logical order after adding them
        - Configure nodes with sensible defaults based on context
        - Keep responses concise — say what you did, not lengthy explanations
        - When adding if_else nodes, always connect both true and false branches
        INSTRUCTIONS;
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return collect($this->conversationHistory)
            ->map(fn (array $msg) => new Message($msg['role'], $msg['content']))
            ->all();
    }

    /**
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [
            // Mutation tools
            new AddWorkflowNodeAiTool($this->workflow),
            new ConnectWorkflowNodesAiTool($this->workflow),
            new ConfigureWorkflowNodeAiTool,
            new RemoveWorkflowNodeAiTool,
            new RemoveWorkflowEdgeAiTool,
            new GetWorkflowStateAiTool($this->workflow),
            new GenerateMissingFeaturePromptAiTool,

            // Discovery tools (lazy-load registry data)
            new ListAvailableTriggersAiTool,
            new ListAvailableActionsAiTool,
            new ListAvailableConditionsAiTool,
            new ListAvailableWaitsAiTool,
            new ListDynamicValuesAiTool,
        ];
    }
}
