<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class TaskCompleted implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Task Completed';
    }

    public static function getCategory(): string
    {
        return 'Tasks';
    }

    public static function getDescription(): string
    {
        return 'Fires when a task is marked as completed.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['deal', 'contact', 'workflow', 'datetime'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        return $event === 'task.completed';
    }
}
