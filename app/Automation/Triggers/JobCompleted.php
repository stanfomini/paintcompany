<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class JobCompleted implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Job Completed';
    }

    public static function getCategory(): string
    {
        return 'Jobs';
    }

    public static function getDescription(): string
    {
        return 'Fires when a painting job is marked as completed.';
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
        return $event === 'job.completed';
    }
}
