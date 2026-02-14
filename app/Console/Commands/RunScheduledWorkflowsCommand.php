<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Console\Command;

class RunScheduledWorkflowsCommand extends Command
{
    protected $signature = 'workflows:run-scheduled {--schedule= : Only run workflows matching this schedule}';

    protected $description = 'Execute workflows with cron-based triggers on their defined schedule';

    /**
     * Map schedule config values to the minutes they should run on.
     *
     * @var array<string, callable>
     */
    protected array $scheduleMatchers = [];

    public function __construct()
    {
        parent::__construct();

        $this->scheduleMatchers = [
            'every_15_min' => fn () => true,
            'hourly' => fn () => now()->minute === 0,
            'daily_9am' => fn () => now()->hour === 9 && now()->minute < 15,
            'daily_5pm' => fn () => now()->hour === 17 && now()->minute < 15,
            'weekly_monday' => fn () => now()->isMonday() && now()->hour === 9 && now()->minute < 15,
        ];
    }

    public function handle(WorkflowEngine $engine): int
    {
        $filterSchedule = $this->option('schedule');

        $workflows = Workflow::query()
            ->active()
            ->published()
            ->with(['nodes'])
            ->get();

        $triggered = 0;

        foreach ($workflows as $workflow) {
            $triggerNode = $workflow->nodes->firstWhere('type', 'trigger');

            if (! $triggerNode || ! $triggerNode->is_enabled) {
                continue;
            }

            // Only process ScheduledCron triggers
            if (! str_contains($triggerNode->node_class, 'ScheduledCron')) {
                continue;
            }

            $schedule = $triggerNode->config['schedule'] ?? null;

            if (! $schedule) {
                continue;
            }

            if ($filterSchedule && $schedule !== $filterSchedule) {
                continue;
            }

            if (! $this->shouldRunNow($schedule)) {
                continue;
            }

            // Use a system user or first internal user as the subject
            $subject = User::where('type', 'internal')->first();

            if (! $subject) {
                $this->warn('No internal user found to use as workflow subject.');

                continue;
            }

            $engine->handleEvent('cron.tick', $subject, [
                'schedule' => $schedule,
            ]);

            $triggered++;
        }

        $this->info("Triggered {$triggered} scheduled workflow(s).");

        return self::SUCCESS;
    }

    protected function shouldRunNow(string $schedule): bool
    {
        $matcher = $this->scheduleMatchers[$schedule] ?? null;

        return $matcher ? $matcher() : false;
    }
}
