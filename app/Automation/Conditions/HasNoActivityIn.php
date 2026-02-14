<?php

namespace App\Automation\Conditions;

use App\Automation\Contracts\ConditionInterface;
use App\Models\Activity;
use App\Models\WorkflowRun;
use Illuminate\Support\Carbon;

class HasNoActivityIn implements ConditionInterface
{
    public static function getName(): string
    {
        return 'Has No Activity In';
    }

    public static function getCategory(): string
    {
        return 'Activity Conditions';
    }

    public static function getDescription(): string
    {
        return 'Checks whether the subject has had no logged activity within the specified number of days.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'days' => [
                'type' => 'integer',
                'label' => 'Days',
                'description' => 'The number of days to look back for activity.',
                'required' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function evaluate(WorkflowRun $run, array $config): bool
    {
        $days = $config['days'] ?? null;
        $subject = $run->subject;

        if ($days === null || $subject === null) {
            return false;
        }

        $since = Carbon::now()->subDays($days);

        $recentCount = Activity::query()
            ->forSubject($subject)
            ->where('created_at', '>=', $since)
            ->count();

        return $recentCount === 0;
    }
}
