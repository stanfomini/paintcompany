<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Activity;
use App\Models\WorkflowRun;
use App\Services\PriorityScoreService;

class CreateFollowUp implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Follow-Up';
    }

    public static function getCategory(): string
    {
        return 'Task Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a follow-up activity with a due date calculated from now. Supports dynamic values in the title.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'label' => 'Follow-Up Title',
                'description' => 'The title of the follow-up. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
            'due_in_days' => [
                'type' => 'integer',
                'label' => 'Due In (Days)',
                'description' => 'Number of days from now until the follow-up is due.',
                'required' => false,
                'default' => 1,
            ],
            'type' => [
                'type' => 'string',
                'label' => 'Activity Type',
                'description' => 'The type of follow-up activity.',
                'required' => false,
                'default' => 'call',
                'options' => Activity::TYPES,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);

        $title = $registry->resolveDynamicValues($config['title'] ?? 'Follow-Up', $run);
        $dueInDays = $config['due_in_days'] ?? 1;
        $type = $config['type'] ?? 'call';

        $subject = $run->subject;

        $activity = Activity::create([
            'user_id' => $run->triggered_by,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'type' => $type,
            'title' => $title,
            'priority' => 'normal',
            'due_at' => now()->addDays($dueInDays),
            'is_completed' => false,
        ]);

        app(PriorityScoreService::class)->updateScore($activity);

        return ['activity_id' => $activity->id];
    }
}
