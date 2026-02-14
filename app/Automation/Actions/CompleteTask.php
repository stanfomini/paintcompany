<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Models\Activity;
use App\Models\WorkflowRun;

class CompleteTask implements ActionInterface
{
    public static function getName(): string
    {
        return 'Complete Task';
    }

    public static function getCategory(): string
    {
        return 'Task Management';
    }

    public static function getDescription(): string
    {
        return 'Marks all incomplete activities matching an automation key as completed on the workflow subject.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'automation_key' => [
                'type' => 'string',
                'label' => 'Automation Key',
                'description' => 'The automation key used to identify tasks to complete.',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $automationKey = $config['automation_key'] ?? '';
        $subject = $run->subject;

        if (! $subject || ! $automationKey) {
            return ['completed_count' => 0];
        }

        $activities = Activity::query()
            ->forSubject($subject)
            ->byAutomationKey($automationKey)
            ->incomplete()
            ->get();

        $count = 0;
        foreach ($activities as $activity) {
            $activity->markAsCompleted();
            $count++;
        }

        return ['completed_count' => $count];
    }
}
