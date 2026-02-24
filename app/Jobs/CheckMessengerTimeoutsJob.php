<?php

namespace App\Jobs;

use App\Services\MessengerBotFlowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class CheckMessengerTimeoutsJob implements ShouldQueue
{
    use Queueable;

    public function handle(MessengerBotFlowService $service): void
    {
        $count = $service->checkTimeouts();

        if ($count > 0) {
            Log::info("Messenger timeout check: {$count} conversations abandoned");
        }
    }
}
