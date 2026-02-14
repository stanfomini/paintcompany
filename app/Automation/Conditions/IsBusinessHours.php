<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;

class IsBusinessHours implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Is Business Hours';
    }

    public static function getCategory(): string
    {
        return 'Business Rules';
    }

    public static function getDescription(): string
    {
        return 'Checks whether the current time falls within configured business hours and days.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'start_hour' => [
                'type' => 'integer',
                'label' => 'Start Hour',
                'description' => 'The business day start hour (0-23). Defaults to 9.',
                'required' => false,
                'default' => 9,
            ],
            'end_hour' => [
                'type' => 'integer',
                'label' => 'End Hour',
                'description' => 'The business day end hour (0-23). Defaults to 17.',
                'required' => false,
                'default' => 17,
            ],
            'days' => [
                'type' => 'array',
                'label' => 'Business Days',
                'description' => 'Weekday numbers (0=Sunday, 1=Monday, ..., 6=Saturday). Defaults to Monday-Friday.',
                'required' => false,
                'default' => [1, 2, 3, 4, 5],
                'items' => [
                    'type' => 'integer',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $startHour = $config['start_hour'] ?? 9;
        $endHour = $config['end_hour'] ?? 17;
        $days = $config['days'] ?? [1, 2, 3, 4, 5];

        $now = Carbon::now();
        $currentHour = $now->hour;
        $currentDay = $now->dayOfWeek;

        if (! in_array($currentDay, $days, true)) {
            return false;
        }

        return $currentHour >= $startHour && $currentHour < $endHour;
    }
}
