<?php

namespace App\Automation\Triggers;

use App\Automation\Contracts\TriggerInterface;
use Illuminate\Database\Eloquent\Model;

class ScheduledCron implements TriggerInterface
{
    public static function getName(): string
    {
        return 'Scheduled (Cron)';
    }

    public static function getCategory(): string
    {
        return 'Time-Based';
    }

    public static function getDescription(): string
    {
        return 'Fires on a recurring schedule such as daily, hourly, or every 15 minutes.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'schedule' => [
                'type' => 'string',
                'label' => 'Schedule',
                'description' => 'How often this trigger should fire.',
                'required' => true,
                'options' => [
                    'every_15_min' => 'Every 15 Minutes',
                    'hourly' => 'Hourly',
                    'daily_9am' => 'Daily at 9 AM',
                    'daily_5pm' => 'Daily at 5 PM',
                    'weekly_monday' => 'Weekly on Monday',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $config
     */
    public function matches(string $event, Model $subject, array $context, array $config): bool
    {
        if ($event !== 'cron.tick') {
            return false;
        }

        $schedule = $config['schedule'] ?? null;

        if ($schedule === null) {
            return false;
        }

        return ($context['schedule'] ?? null) === $schedule;
    }
}
