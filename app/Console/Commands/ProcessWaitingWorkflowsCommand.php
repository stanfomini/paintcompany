<?php

namespace App\Console\Commands;

use App\Services\WorkflowEngine;
use Illuminate\Console\Command;

class ProcessWaitingWorkflowsCommand extends Command
{
    protected $signature = 'workflows:process-waiting';

    protected $description = 'Resume workflows paused on wait nodes that are due';

    public function handle(WorkflowEngine $engine): int
    {
        $processed = $engine->processWaitingSteps();

        $this->info("Processed {$processed} waiting workflow step(s).");

        return self::SUCCESS;
    }
}
