<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class TaskOverdue implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Task Overdue';
    }

    public static function getCategory(): string
    {
        return 'Tasks';
    }

    public static function getDescription(): string
    {
        return 'Fires when a task passes its due date without being completed.';
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
        return $event === 'task.overdue';
    }
}
