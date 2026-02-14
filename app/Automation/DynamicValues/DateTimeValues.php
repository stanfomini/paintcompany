<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\WorkflowRun;

class DateTimeValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'datetime';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return ['now', 'today', 'tomorrow', 'day_of_week'];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        return match ($key) {
            'now' => now()->format('M j, Y g:i A'),
            'today' => now()->format('M j, Y'),
            'tomorrow' => now()->addDay()->format('M j, Y'),
            'day_of_week' => now()->format('l'),
            default => null,
        };
    }
}
