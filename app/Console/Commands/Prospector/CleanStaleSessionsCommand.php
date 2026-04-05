<?php

namespace App\Console\Commands\Prospector;

use App\Models\Prospector\Call;
use App\Models\Prospector\DialerSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanStaleSessionsCommand extends Command
{
    protected $signature = 'prospector:clean-stale';

    protected $description = 'Clean up stale dialer sessions and calls that were never properly closed';

    public function handle(): int
    {
        $count = 0;

        // End sessions that have been active/paused for more than 2 hours
        $staleSessions = DialerSession::whereIn('status', ['active', 'paused'])
            ->where('created_at', '<', now()->subHours(2))
            ->get();

        foreach ($staleSessions as $session) {
            $session->update(['status' => 'ended', 'ended_at' => now()]);
            $count++;
        }

        if ($staleSessions->isNotEmpty()) {
            Log::info('Cleaned stale dialer sessions', ['count' => $staleSessions->count()]);
        }

        // Complete calls stuck in active states for more than 2 hours
        $staleCalls = Call::whereIn('status', ['ringing', 'in-progress', 'initiating', 'holding'])
            ->where('started_at', '<', now()->subHours(2))
            ->get();

        foreach ($staleCalls as $call) {
            $call->update(['status' => 'completed', 'ended_at' => now()]);
            $count++;
        }

        if ($staleCalls->isNotEmpty()) {
            Log::info('Cleaned stale calls', ['count' => $staleCalls->count()]);
        }

        if ($count > 0) {
            $this->info("Cleaned {$count} stale record(s).");
        }

        return self::SUCCESS;
    }
}
