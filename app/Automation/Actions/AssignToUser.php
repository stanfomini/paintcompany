<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Models\WorkflowRun;

class AssignToUser implements ActionInterface
{
    public static function getName(): string
    {
        return 'Assign to User';
    }

    public static function getCategory(): string
    {
        return 'Data Management';
    }

    public static function getDescription(): string
    {
        return 'Assigns the workflow subject (deal, job, or activity) to a specific user.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'user_id' => [
                'type' => 'integer',
                'label' => 'Assign To User',
                'description' => 'The user ID to assign the subject to.',
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
        $userId = $config['user_id'] ?? null;
        $subject = $run->subject;

        if (! $subject || ! $userId) {
            return ['assigned_to' => null];
        }

        $subject->update(['assigned_to' => $userId]);

        return ['assigned_to' => $userId];
    }
}
