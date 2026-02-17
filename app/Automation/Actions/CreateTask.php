<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Activity;
use App\Models\WorkflowRun;
use App\Services\PriorityScoreService;

class CreateTask implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Task';
    }

    public static function getCategory(): string
    {
        return 'Task Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a new task activity on the workflow subject. Supports dynamic values in the title.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'label' => 'Task Title',
                'description' => 'The title of the task. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
            'type' => [
                'type' => 'string',
                'label' => 'Activity Type',
                'description' => 'The type of activity to create.',
                'required' => false,
                'default' => 'task',
                'options' => Activity::TYPES,
            ],
            'priority' => [
                'type' => 'string',
                'label' => 'Priority',
                'description' => 'The priority level of the task.',
                'required' => false,
                'default' => 'normal',
                'options' => Activity::PRIORITIES,
            ],
            'due_in_hours' => [
                'type' => 'integer',
                'label' => 'Due In (Hours)',
                'description' => 'Number of hours from now until the task is due. Leave empty for no due date.',
                'required' => false,
                'default' => null,
            ],
            'assigned_to' => [
                'type' => 'integer',
                'label' => 'Assign To User',
                'description' => 'The user ID to assign this task to. Leave empty for unassigned.',
                'required' => false,
                'default' => null,
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

        $title = $registry->resolveDynamicValues($config['title'] ?? 'Automated Task', $run);
        $type = $config['type'] ?? 'task';
        $priority = $config['priority'] ?? 'normal';
        $dueInHours = isset($config['due_in_hours']) && $config['due_in_hours'] !== '' ? (int) $config['due_in_hours'] : null;
        $assignedTo = $config['assigned_to'] ?? null;

        $subject = $run->subject;

        $activity = Activity::create([
            'user_id' => $run->triggered_by,
            'assigned_to' => $assignedTo,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'type' => $type,
            'title' => $title,
            'priority' => $priority,
            'due_at' => $dueInHours ? now()->addHours($dueInHours) : null,
            'is_completed' => false,
        ]);

        app(PriorityScoreService::class)->updateScore($activity);

        return ['activity_id' => $activity->id];
    }
}
