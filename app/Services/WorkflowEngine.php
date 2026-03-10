<?php

namespace App\Services;

use App\Automation\Registry;
use App\Models\Workflow;
use App\Models\WorkflowEdge;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class WorkflowEngine
{
    public function __construct(
        protected Registry $registry
    ) {}

    /**
     * Handle an incoming event and start matching workflows.
     *
     * @param  array<string, mixed>  $context
     */
    public function handleEvent(string $event, Model $subject, array $context = []): void
    {
        $workflows = Workflow::query()
            ->active()
            ->published()
            ->with(['nodes', 'edges'])
            ->get();

        foreach ($workflows as $workflow) {
            $triggerNode = $workflow->nodes->firstWhere('type', 'trigger');

            if (! $triggerNode || ! $triggerNode->is_enabled) {
                continue;
            }

            $trigger = $this->registry->findTrigger($triggerNode->node_class);

            if (! $trigger) {
                continue;
            }

            if ($trigger->matches($event, $subject, $context, $triggerNode->config ?? [])) {
                $this->startWorkflow($workflow, $triggerNode, $subject, $context);
            }
        }
    }

    /**
     * Start a workflow run from its trigger node.
     *
     * @param  array<string, mixed>  $context
     */
    public function startWorkflow(
        Workflow $workflow,
        WorkflowNode $triggerNode,
        Model $subject,
        array $context = [],
        ?int $triggeredBy = null,
    ): WorkflowRun {
        $run = WorkflowRun::create([
            'workflow_id' => $workflow->id,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'status' => 'running',
            'context' => $context,
            'current_node_id' => $triggerNode->id,
            'triggered_at' => now(),
            'triggered_by' => $triggeredBy ?? auth()->id() ?? $this->resolveSystemUser($subject),
        ]);

        // Record trigger step as completed
        WorkflowRunStep::create([
            'workflow_run_id' => $run->id,
            'workflow_node_id' => $triggerNode->id,
            'status' => 'completed',
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        // Advance to the next node(s) after the trigger
        $this->advanceFromNode($run, $triggerNode);

        return $run;
    }

    /**
     * Resume a paused workflow run (after a wait completes).
     */
    public function resume(WorkflowRun $run): void
    {
        if ($run->status !== 'waiting') {
            return;
        }

        $run->update(['status' => 'running']);

        $waitingStep = $run->steps()
            ->where('status', 'waiting')
            ->first();

        if (! $waitingStep) {
            $this->completeRun($run);

            return;
        }

        $node = $waitingStep->node;

        // Check if wait should resume
        $wait = $this->registry->findWait($node->node_class);

        if ($wait && $wait->shouldResume($waitingStep, $run, $node->config ?? [])) {
            $waitingStep->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->advanceFromNode($run, $node);
        } else {
            // Not ready yet, go back to waiting
            $run->update(['status' => 'waiting']);
        }
    }

    /**
     * Execute a specific node in the workflow graph.
     */
    public function executeNode(WorkflowRun $run, WorkflowNode $node): void
    {
        if (! $node->is_enabled) {
            // Skip disabled nodes, advance to next
            $this->recordStep($run, $node, 'skipped');
            $this->advanceFromNode($run, $node);

            return;
        }

        match ($node->type) {
            'action' => $this->executeActionNode($run, $node),
            'if_else' => $this->executeIfElseNode($run, $node),
            'wait' => $this->executeWaitNode($run, $node),
            'goal' => $this->executeGoalNode($run, $node),
            'goto' => $this->executeGotoNode($run, $node),
            default => $this->advanceFromNode($run, $node),
        };
    }

    /**
     * Cancel a running workflow.
     */
    public function cancel(WorkflowRun $run): void
    {
        $run->steps()
            ->whereIn('status', ['pending', 'waiting', 'executing'])
            ->update([
                'status' => 'skipped',
                'completed_at' => now(),
            ]);

        $run->update([
            'status' => 'cancelled',
            'completed_at' => now(),
        ]);
    }

    /**
     * Process all waiting steps that are due.
     */
    public function processWaitingSteps(): int
    {
        $dueSteps = WorkflowRunStep::query()
            ->due()
            ->with(['run', 'node'])
            ->get();

        $processed = 0;

        foreach ($dueSteps as $step) {
            $run = $step->run;

            if ($run->status !== 'waiting') {
                continue;
            }

            $this->resume($run->fresh());
            $processed++;
        }

        return $processed;
    }

    /**
     * Execute an action node.
     */
    protected function executeActionNode(WorkflowRun $run, WorkflowNode $node): void
    {
        $action = $this->registry->findAction($node->node_class);

        if (! $action) {
            $this->recordStep($run, $node, 'failed', errorMessage: "Action class not found: {$node->node_class}");
            $this->failRun($run, "Action class not found: {$node->node_class}");

            return;
        }

        $step = $this->recordStep($run, $node, 'executing');

        try {
            $config = $this->resolveConfigValues($run, $node->config ?? []);
            $result = $action->execute($run, $config);

            $step->update([
                'status' => 'completed',
                'completed_at' => now(),
                'result' => $result,
            ]);

            // Merge action result into run context
            $context = $run->context ?? [];
            $context['last_action_result'] = $result;
            $run->update(['context' => $context]);

            $this->advanceFromNode($run, $node);
        } catch (\Throwable $e) {
            Log::error("Workflow action failed: {$e->getMessage()}", [
                'workflow_run_id' => $run->id,
                'node_id' => $node->id,
                'node_class' => $node->node_class,
            ]);

            $step->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            $this->failRun($run, $e->getMessage());
        }
    }

    /**
     * Execute an IF/ELSE branching node.
     */
    protected function executeIfElseNode(WorkflowRun $run, WorkflowNode $node): void
    {
        $step = $this->recordStep($run, $node, 'executing');

        $winningEdge = $this->evaluateBranches($node, $run);

        $step->update([
            'status' => 'completed',
            'completed_at' => now(),
            'result' => ['branch' => $winningEdge?->branch ?? 'none'],
        ]);

        if ($winningEdge) {
            $nextNode = $winningEdge->toNode;

            if ($nextNode) {
                $run->update(['current_node_id' => $nextNode->id]);
                $this->executeNode($run, $nextNode);
            } else {
                $this->completeRun($run);
            }
        } else {
            // No matching branch - check for "else" branch
            $elseBranch = $node->outgoingEdges()
                ->where('branch', 'else')
                ->orderBy('sort_order')
                ->first();

            if ($elseBranch && $elseBranch->toNode) {
                $run->update(['current_node_id' => $elseBranch->toNode->id]);
                $this->executeNode($run, $elseBranch->toNode);
            } else {
                $this->completeRun($run);
            }
        }
    }

    /**
     * Execute a wait node.
     */
    protected function executeWaitNode(WorkflowRun $run, WorkflowNode $node): void
    {
        $wait = $this->registry->findWait($node->node_class);

        if (! $wait) {
            $this->recordStep($run, $node, 'failed', errorMessage: "Wait class not found: {$node->node_class}");
            $this->failRun($run, "Wait class not found: {$node->node_class}");

            return;
        }

        $config = $this->resolveConfigValues($run, $node->config ?? []);
        $resumeAt = $wait->getResumeAt($run, $config);

        WorkflowRunStep::create([
            'workflow_run_id' => $run->id,
            'workflow_node_id' => $node->id,
            'status' => 'waiting',
            'started_at' => now(),
            'scheduled_for' => $resumeAt,
        ]);

        $run->update([
            'status' => 'waiting',
            'current_node_id' => $node->id,
        ]);
    }

    /**
     * Execute a goal node (exit condition).
     */
    protected function executeGoalNode(WorkflowRun $run, WorkflowNode $node): void
    {
        $step = $this->recordStep($run, $node, 'completed');
        $step->update(['completed_at' => now()]);

        $this->completeRun($run);
    }

    /**
     * Execute a goto node (jump to another node or workflow).
     */
    protected function executeGotoNode(WorkflowRun $run, WorkflowNode $node): void
    {
        $config = $node->config ?? [];
        $step = $this->recordStep($run, $node, 'completed');
        $step->update(['completed_at' => now()]);

        if (isset($config['target_workflow_id'])) {
            // Jump to another workflow
            $targetWorkflow = Workflow::find($config['target_workflow_id']);

            if ($targetWorkflow) {
                $triggerNode = $targetWorkflow->nodes()->where('type', 'trigger')->first();

                if ($triggerNode && $run->subject) {
                    $this->startWorkflow($targetWorkflow, $triggerNode, $run->subject, $run->context ?? []);
                }
            }

            $this->completeRun($run);
        } else {
            // Just advance normally via edges
            $this->advanceFromNode($run, $node);
        }
    }

    /**
     * Evaluate IF/ELSE branches to find the winning branch.
     */
    protected function evaluateBranches(WorkflowNode $ifElseNode, WorkflowRun $run): ?WorkflowEdge
    {
        $edges = $ifElseNode->outgoingEdges()
            ->where('branch', '!=', 'else')
            ->orderBy('sort_order')
            ->get();

        foreach ($edges as $edge) {
            if ($this->evaluateBranchConditions($edge, $run)) {
                return $edge;
            }
        }

        return null;
    }

    /**
     * Evaluate conditions on a branch edge.
     */
    protected function evaluateBranchConditions(WorkflowEdge $edge, WorkflowRun $run): bool
    {
        $conditionConfig = $edge->condition_config;

        if (empty($conditionConfig)) {
            return true; // No conditions means always match
        }

        $conditions = $conditionConfig['conditions'] ?? [];
        $logic = $conditionConfig['logic'] ?? 'and';

        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $conditionDef) {
            $conditionClass = $conditionDef['class'] ?? null;

            if (! $conditionClass) {
                continue;
            }

            $condition = $this->registry->findCondition($conditionClass);

            if (! $condition) {
                continue;
            }

            $result = $condition->evaluate($run, $conditionDef['config'] ?? []);

            if ($logic === 'or' && $result) {
                return true;
            }

            if ($logic === 'and' && ! $result) {
                return false;
            }
        }

        return $logic === 'and';
    }

    /**
     * Advance from a completed node to the next node(s) via edges.
     */
    protected function advanceFromNode(WorkflowRun $run, WorkflowNode $fromNode): void
    {
        // Check for goal conditions first
        $goalNode = $this->checkGoals($run);

        if ($goalNode) {
            $this->executeNode($run, $goalNode);

            return;
        }

        $edges = $fromNode->outgoingEdges()
            ->orderBy('sort_order')
            ->get();

        if ($edges->isEmpty()) {
            $this->completeRun($run);

            return;
        }

        // For non-branching nodes, take the first (default) edge
        $edge = $edges->first();

        if (! $edge->toNode) {
            $this->completeRun($run);

            return;
        }

        $nextNode = $edge->toNode;
        $run->update(['current_node_id' => $nextNode->id]);

        $this->executeNode($run, $nextNode);
    }

    /**
     * Check if any goal node conditions are met.
     */
    protected function checkGoals(WorkflowRun $run): ?WorkflowNode
    {
        $workflow = $run->workflow;

        if (! $workflow) {
            return null;
        }

        $goalNodes = $workflow->nodes()
            ->where('type', 'goal')
            ->where('is_enabled', true)
            ->get();

        foreach ($goalNodes as $goalNode) {
            $config = $goalNode->config ?? [];
            $conditions = $config['conditions'] ?? [];

            if (empty($conditions)) {
                continue;
            }

            $allMet = true;

            foreach ($conditions as $conditionDef) {
                $conditionClass = $conditionDef['class'] ?? null;

                if (! $conditionClass) {
                    continue;
                }

                $condition = $this->registry->findCondition($conditionClass);

                if (! $condition || ! $condition->evaluate($run, $conditionDef['config'] ?? [])) {
                    $allMet = false;
                    break;
                }
            }

            if ($allMet) {
                return $goalNode;
            }
        }

        return null;
    }

    /**
     * Mark a run as completed.
     */
    protected function completeRun(WorkflowRun $run): void
    {
        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark a run as failed.
     */
    protected function failRun(WorkflowRun $run, string $message): void
    {
        $run->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $message,
        ]);
    }

    /**
     * Record a step for a node execution.
     */
    protected function recordStep(
        WorkflowRun $run,
        WorkflowNode $node,
        string $status,
        ?string $errorMessage = null,
    ): WorkflowRunStep {
        return WorkflowRunStep::create([
            'workflow_run_id' => $run->id,
            'workflow_node_id' => $node->id,
            'status' => $status,
            'started_at' => now(),
            'completed_at' => in_array($status, ['completed', 'skipped', 'failed']) ? now() : null,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Resolve a system user for unauthenticated triggers (e.g. webhooks).
     */
    private function resolveSystemUser(Model $subject): ?int
    {
        // For webhook-triggered workflows, use the webhook creator as the acting user
        if ($subject instanceof \App\Models\AutomationWebhook) {
            return $subject->created_by;
        }

        // Fallback: first internal user (system admin)
        return \App\Models\User::where('type', 'internal')->first()?->id;
    }

    /**
     * Resolve dynamic values in a config array.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function resolveConfigValues(WorkflowRun $run, array $config): array
    {
        $resolved = [];

        foreach ($config as $key => $value) {
            if (is_string($value)) {
                $resolved[$key] = $this->registry->resolveDynamicValues($value, $run);
            } elseif (is_array($value)) {
                $resolved[$key] = $this->resolveConfigValues($run, $value);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }
}
