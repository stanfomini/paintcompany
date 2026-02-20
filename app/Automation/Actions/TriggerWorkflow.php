<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Models\Workflow;
use App\Models\WorkflowRun;

class TriggerWorkflow implements ActionInterface
{
    public static function getName(): string
    {
        return 'Trigger Workflow';
    }

    public static function getCategory(): string
    {
        return 'Workflow Control';
    }

    public static function getDescription(): string
    {
        return 'Starts another workflow with the same subject as the current run.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'workflow_id' => [
                'type' => 'string',
                'label' => 'Target Workflow',
                'description' => 'The workflow to trigger.',
                'required' => true,
                'options' => Workflow::active()->published()->pluck('name', 'id')->toArray(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $workflowId = $config['workflow_id'] ?? null;

        if (! $workflowId) {
            return ['workflow_run_id' => null];
        }

        $workflow = Workflow::find($workflowId);

        if (! $workflow) {
            return ['workflow_run_id' => null];
        }

        $newRun = WorkflowRun::create([
            'workflow_id' => $workflow->id,
            'subject_type' => $run->subject_type,
            'subject_id' => $run->subject_id,
            'status' => 'running',
            'context' => [
                'triggered_by_workflow_run' => $run->id,
            ],
            'triggered_at' => now(),
            'triggered_by' => $run->triggered_by,
        ]);

        return ['workflow_run_id' => $newRun->id];
    }
}
