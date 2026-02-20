<?php

namespace App\Console\Commands;

use App\Services\TerritoryDispatchService;
use Illuminate\Console\Command;

class ExpireTerritoryOffers extends Command
{
    protected $signature = 'territory:expire-offers';

    protected $description = 'Expire territory offers that have passed their deadline';

    public function handle(TerritoryDispatchService $service): int
    {
        $count = $service->expireOffers();

        if ($count > 0) {
            $this->info("Expired {$count} territory offer(s).");
        }

        return self::SUCCESS;
    }
}
