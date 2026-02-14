<?php

namespace App\Automation\Waits;

use App\Automation\Contracts\WaitInterface;
use App\Models\WorkflowRun;
use App\Models\WorkflowRunStep;
use Carbon\Carbon;

class WaitUntilDate implements WaitInterface
{
    public static function getName(): string
    {
        return 'Wait Until Date';
    }

    public static function getCategory(): string
    {
        return 'Time-Based';
    }

    public static function getDescription(): string
    {
        return 'Pauses the workflow until a specific date and time is reached.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'date' => [
                'type' => 'string',
                'label' => 'Resume Date',
                'description' => 'The date and time to resume the workflow (Y-m-d H:i:s format).',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function getResumeAt(WorkflowRun $run, array $config): Carbon
    {
        return Carbon::parse($config['date']);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function shouldResume(WorkflowRunStep $step, WorkflowRun $run, array $config): bool
    {
        $date = Carbon::parse($config['date']);

        return $date->lte(now());
    }
}
