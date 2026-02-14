<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;

class TimeSinceEvent implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Time Since Event';
    }

    public static function getCategory(): string
    {
        return 'Time Conditions';
    }

    public static function getDescription(): string
    {
        return 'Checks whether the elapsed time since the trigger fired exceeds or is under a threshold in hours.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'hours' => [
                'type' => 'integer',
                'label' => 'Hours',
                'description' => 'The number of hours to compare against.',
                'required' => true,
            ],
            'operator' => [
                'type' => 'string',
                'label' => 'Operator',
                'description' => 'Whether elapsed time should be greater than or less than the threshold.',
                'required' => true,
                'options' => [
                    '>' => 'More than',
                    '<' => 'Less than',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $hours = $config['hours'] ?? null;
        $operator = $config['operator'] ?? '>';

        if ($hours === null || $run->triggered_at === null) {
            return false;
        }

        $elapsedHours = (int) abs(Carbon::now()->diffInHours($run->triggered_at));

        return match ($operator) {
            '>' => $elapsedHours > $hours,
            '<' => $elapsedHours < $hours,
            default => false,
        };
    }
}
