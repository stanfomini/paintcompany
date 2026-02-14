<?php

namespace App\Automation\Waits;

use App\Automation\Contracts\WaitInterface;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Carbon\Carbon;

class TimeDelay implements WaitInterface
{
    public static function getName(): string
    {
        return 'Time Delay';
    }

    public static function getCategory(): string
    {
        return 'Time-Based';
    }

    public static function getDescription(): string
    {
        return 'Pauses the workflow for a fixed number of hours before continuing.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'hours' => [
                'type' => 'integer',
                'label' => 'Delay (Hours)',
                'description' => 'Number of hours to wait before resuming the workflow.',
                'required' => false,
                'default' => 24,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function getResumeAt(WorkflowRun $run, array $config): Carbon
    {
        $hours = $config['hours'] ?? 24;

        return Carbon::now()->addHours($hours);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function shouldResume(WorkflowRunStep $step, WorkflowRun $run, array $config): bool
    {
        return $step->scheduled_for && $step->scheduled_for->lte(now());
    }
}
