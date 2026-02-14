<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\WorkflowRun;

class WorkflowValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'workflow';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return ['name', 'triggered_at', 'run_id'];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        return match ($key) {
            'name' => $run->workflow?->name,
            'triggered_at' => $run->triggered_at?->format('M j, Y g:i A'),
            'run_id' => (string) $run->id,
            default => null,
        };
    }
}
