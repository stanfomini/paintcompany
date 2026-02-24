<?php

namespace App\Jobs;

use App\Services\FacebookMessengerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessFacebookMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array  $webhookPayload  The full webhook payload from Facebook.
     */
    public function __construct(public array $webhookPayload)
    {
        $this->onConnection('redis');
    }

    public function handle(FacebookMessengerService $messenger): void
    {
        $entries = $this->webhookPayload['entry'] ?? [];

        foreach ($entries as $entry) {
            try {
                $messenger->processIncomingMessage($entry);
            } catch (\Throwable $e) {
                Log::error('ProcessFacebookMessageJob: Failed to process entry', [
                    'entry_id' => $entry['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
