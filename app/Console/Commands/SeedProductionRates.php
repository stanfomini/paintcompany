<?php

namespace App\Console\Commands;

use App\Services\ProductionRateService;
use Illuminate\Console\Command;

class SeedProductionRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'production-rates:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed default painting industry production rates';

    /**
     * Execute the console command.
     */
    public function handle(ProductionRateService $service): int
    {
        $this->info('Seeding default production rates...');

        $service->seedDefaultRates();

        $this->info('Default production rates seeded successfully.');

        return Command::SUCCESS;
    }
}
