<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Activity;
use App\Models\WorkflowRun;
use App\Services\PriorityScoreService;

class AddNote implements ActionInterface
{
    public static function getName(): string
    {
        return 'Add Note';
    }

    public static function getCategory(): string
    {
        return 'Task Management';
    }

    public static function getDescription(): string
    {
        return 'Creates a note activity on the workflow subject. Supports dynamic values in the title.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'title' => [
                'type' => 'string',
                'label' => 'Note Title',
                'description' => 'The title of the note. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
            'description' => [
                'type' => 'string',
                'label' => 'Description',
                'description' => 'Optional description or body of the note.',
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

        $title = $registry->resolveDynamicValues($config['title'] ?? 'Note', $run);
        $description = isset($config['description'])
            ? $registry->resolveDynamicValues($config['description'], $run)
            : null;

        $subject = $run->subject;

        $activity = Activity::create([
            'user_id' => $run->triggered_by,
            'subject_type' => $subject ? $subject->getMorphClass() : null,
            'subject_id' => $subject?->getKey(),
            'type' => 'note',
            'title' => $title,
            'description' => $description,
            'priority' => 'normal',
            'is_completed' => false,
        ]);

        app(PriorityScoreService::class)->updateScore($activity);

        return ['activity_id' => $activity->id];
    }
}
