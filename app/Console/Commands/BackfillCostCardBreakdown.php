<?php

namespace App\Console\Commands;

use App\Models\JobCostCard;
use App\Services\JobCostCardService;
use Illuminate\Console\Command;

class BackfillCostCardBreakdown extends Command
{
    protected $signature = 'cost-cards:backfill-breakdown {--year= : Limit to a specific year}';

    protected $description = 'Backfill job cost cards with full COGS decomposition from breakdown service';

    public function handle(): int
    {
        $service = new JobCostCardService;

        $query = JobCostCard::query()->whereNotNull('estimate_id');

        if ($year = $this->option('year')) {
            $query->where('year', (int) $year);
        }

        $cards = $query->get();
        $total = $cards->count();

        if ($total === 0) {
            $this->info('No cards with estimates found to backfill.');

            return self::SUCCESS;
        }

        $this->info("Backfilling {$total} job cost card(s)...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $synced = 0;

        foreach ($cards as $card) {
            if ($service->refreshBreakdownData($card)) {
                $updated++;
            } else {
                $skipped++;
            }

            if ($card->job_id) {
                $job = $card->job;
                if ($job) {
                    $service->syncPaymentSummary($job);
                    $synced++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done! Updated: {$updated}, Skipped: {$skipped}, Payment syncs: {$synced}");

        return self::SUCCESS;
    }
}
