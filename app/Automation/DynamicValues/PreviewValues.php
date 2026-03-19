<?php

namespace App\Automation\DynamicValues;

use App\Automation\Contracts\DynamicValueInterface;
use App\Models\Deal;
use App\Models\Job;
use App\Models\ProjectPreview;
use App\Models\WorkflowRun;

class PreviewValues implements DynamicValueInterface
{
    public static function getPrefix(): string
    {
        return 'preview';
    }

    /**
     * @return array<int, string>
     */
    public static function getAvailableKeys(): array
    {
        return ['portal_url', 'customer_name', 'status'];
    }

    public function resolve(string $key, WorkflowRun $run): ?string
    {
        $preview = $this->resolvePreview($run);

        if (! $preview) {
            return null;
        }

        return match ($key) {
            'portal_url' => $preview->getPortalUrl(),
            'customer_name' => trim($preview->first_name.' '.$preview->last_name),
            'status' => $preview->status,
            default => null,
        };
    }

    /**
     * Resolve the project preview from the run's subject.
     */
    protected function resolvePreview(WorkflowRun $run): ?ProjectPreview
    {
        $subject = $run->subject;

        if (! $subject) {
            return null;
        }

        if ($subject instanceof ProjectPreview) {
            return $subject;
        }

        if ($subject instanceof Deal) {
            return $subject->projectPreview;
        }

        if ($subject instanceof Job) {
            return $subject->deal?->projectPreview;
        }

        return null;
    }
}
