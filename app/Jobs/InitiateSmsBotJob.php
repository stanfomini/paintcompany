<?php

namespace App\Jobs;

use App\Models\SmsConversation;
use App\Services\SmsEngagementBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InitiateSmsBotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    public function __construct(
        public string $phone,
        public ?int $customerId = null,
        public ?int $leadSourceId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Initiating SMS bot conversation', [
            'phone' => $this->phone,
            'customer_id' => $this->customerId,
        ]);

        // Guard: Check if there's already an active conversation for this phone
        $existing = SmsConversation::findActiveByPhone($this->phone);
        if ($existing) {
            Log::info('SMS bot: active conversation already exists, skipping', [
                'phone' => $this->phone,
                'conversation_id' => $existing->id,
            ]);

            return;
        }

        try {
            $botService = app(SmsEngagementBotService::class);
            $conversation = $botService->initiate($this->phone, $this->customerId, $this->leadSourceId);

            if ($conversation) {
                Log::info('SMS bot conversation initiated', [
                    'phone' => $this->phone,
                    'conversation_id' => $conversation->id,
                ]);
            } else {
                Log::info('SMS bot: initiation skipped (disabled or no matching flow)', [
                    'phone' => $this->phone,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('SMS bot initiation failed', [
                'phone' => $this->phone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('SMS bot initiation job failed permanently', [
            'phone' => $this->phone,
            'error' => $exception?->getMessage(),
        ]);
    }
}
