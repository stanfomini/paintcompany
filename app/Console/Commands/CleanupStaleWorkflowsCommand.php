<?php

namespace App\Console\Commands;

use App\Models\WorkflowRun;
use Illuminate\Console\Command;

class CleanupStaleWorkflowsCommand extends Command
{
    protected $signature = 'workflows:cleanup-stale';

    protected $description = 'Cancel workflow runs stuck in running status for more than 24 hours';

    public function handle(): int
    {
        $staleRuns = WorkflowRun::query()
            ->where('status', 'running')
            ->where('triggered_at', '<', now()->subHours(24))
            ->get();

        $count = $staleRuns->count();

        foreach ($staleRuns as $run) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => 'Automatically cancelled: stuck in running state for over 24 hours.',
            ]);
        }

        $this->info("Cleaned up {$count} stale workflow run(s).");

        return self::SUCCESS;
    }
}
