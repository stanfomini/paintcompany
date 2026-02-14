<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\Job;
use App\Models\WorkflowRun;

class JobValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'job';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return ['title', 'stage', 'stage_label', 'scheduled_date'];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        $subject = $run->subject;

        if (! $subject instanceof Job) {
            return null;
        }

        return match ($key) {
            'title' => $subject->title,
            'stage' => $subject->stage,
            'stage_label' => Job::STAGES[$subject->stage] ?? $subject->stage,
            'scheduled_date' => $subject->scheduled_date?->format('M j, Y'),
            default => null,
        };
    }
}
