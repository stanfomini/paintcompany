<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Models\Deal;
use App\Models\WorkflowRun;

class AdvanceDealStage implements ActionInterface
{
    public static function getName(): string
    {
        return 'Advance Deal Stage';
    }

    public static function getCategory(): string
    {
        return 'Deal Management';
    }

    public static function getDescription(): string
    {
        return 'Moves a deal to a specified pipeline stage. The workflow subject must be a Deal.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'stage' => [
                'type' => 'string',
                'label' => 'Target Stage',
                'description' => 'The stage to move the deal to.',
                'required' => true,
                'options' => Deal::STAGES,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $stage = $config['stage'] ?? null;
        $subject = $run->subject;

        if (! $subject instanceof Deal || ! $stage) {
            return ['from_stage' => null, 'to_stage' => null];
        }

        if (! array_key_exists($stage, Deal::STAGES)) {
            return ['from_stage' => $subject->stage, 'to_stage' => null];
        }

        $oldStage = $subject->stage;

        if (method_exists($subject, 'canTransitionTo') && ! $subject->canTransitionTo($stage)) {
            return ['from_stage' => $oldStage, 'to_stage' => null];
        }

        $subject->update(['stage' => $stage]);

        return ['from_stage' => $oldStage, 'to_stage' => $stage];
    }
}
