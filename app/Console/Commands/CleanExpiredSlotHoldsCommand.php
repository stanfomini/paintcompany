<?php

namespace App\Console\Commands;

use App\Models\SlotHold;
use Illuminate\Console\Command;

class CleanExpiredSlotHoldsCommand extends Command
{
    protected $signature = 'booking:clean-holds';

    protected $description = 'Clean up expired appointment slot holds';

    public function handle(): int
    {
        $count = SlotHold::expired()->count();

        SlotHold::expired()->delete();

        $this->info("Cleaned up {$count} expired slot holds.");

        return self::SUCCESS;
    }
}
