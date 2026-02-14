<?php

namespace App\Console\Commands;

use App\Services\PriorityScoreService;
use Illuminate\Console\Command;

class RecalculatePriorityScoresCommand extends Command
{
    protected $signature = 'priority-scores:recalculate';

    protected $description = 'Recalculate priority scores for all incomplete activities';

    public function handle(PriorityScoreService $service): int
    {
        $updated = $service->recalculateAll();

        $this->info("Recalculated priority scores: {$updated} activities updated.");

        return self::SUCCESS;
    }
}
