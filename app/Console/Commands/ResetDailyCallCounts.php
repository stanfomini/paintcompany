<?php

namespace App\Console\Commands;

use App\Models\Prospector\CampaignContact;
use Illuminate\Console\Command;

class ResetDailyCallCounts extends Command
{
    protected $signature = 'prospector:reset-daily-calls';

    protected $description = 'Reset calls_today counter for all campaign contacts (runs at midnight)';

    public function handle(): int
    {
        $updated = CampaignContact::where('calls_today', '>', 0)->update(['calls_today' => 0]);
        $this->info("Reset calls_today for {$updated} campaign contacts.");

        return self::SUCCESS;
    }
}
