<?php

namespace App\Console\Commands\Prospector;

use App\Services\Prospector\SequenceEngine;
use Illuminate\Console\Command;

class ExecuteSequencesCommand extends Command
{
    protected $signature = 'prospector:execute-sequences';

    protected $description = 'Process due sequence enrollments and execute their next steps';

    public function handle(SequenceEngine $engine): int
    {
        $count = $engine->processDueEnrollments();

        $this->info("Processed {$count} sequence steps.");

        return self::SUCCESS;
    }
}
