<?php

namespace App\Console\Commands;

use App\Services\SlaMetricService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateSlaMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sla:calculate-metrics {--date= : Calculate metrics for a specific date (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate SLA metrics for all internal users';

    /**
     * Execute the console command.
     */
    public function handle(SlaMetricService $service): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();

        $this->info("Calculating SLA metrics for {$date->toDateString()}...");

        $service->calculateDaily($date);

        $this->info('SLA metrics calculated successfully.');

        return self::SUCCESS;
    }
}
