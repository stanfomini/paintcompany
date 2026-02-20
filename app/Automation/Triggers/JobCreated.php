<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class JobCreated implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Job Created';
    }

    public static function getCategory(): string
    {
        return 'Jobs';
    }

    public static function getDescription(): string
    {
        return 'Fires when a new painting job is created.';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableDynamicPrefixes(): array
    {
        return ['job', 'deal', 'contact', 'workflow', 'datetime'];
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
        return $event === 'job.created';
    }
}
