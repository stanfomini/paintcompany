<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\Deal;
use App\Models\Job;
use App\Models\WorkflowRun;

class DealValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'deal';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return ['title', 'stage', 'estimated_value', 'stage_label'];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        $deal = $this->resolveDeal($run);

        if (! $deal) {
            return null;
        }

        return match ($key) {
            'title' => $deal->title,
            'stage' => $deal->stage,
            'estimated_value' => $deal->estimated_value ? number_format((float) $deal->estimated_value, 2) : null,
            'stage_label' => Deal::STAGES[$deal->stage] ?? $deal->stage,
            default => null,
        };
    }

    /**
     * Resolve the deal from the run's subject.
     */
    protected function resolveDeal(WorkflowRun $run): ?Deal
    {
        $subject = $run->subject;

        if ($subject instanceof Deal) {
            return $subject;
        }

        if ($subject instanceof Job) {
            return $subject->deal;
        }

        return null;
    }
}
