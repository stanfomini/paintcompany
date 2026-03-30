<?php

namespace App\Jobs\Prospector;

use App\Models\Prospector\Call;
use App\Services\Prospector\CallIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCallIntelligenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Call $call,
    ) {
        $this->onQueue('prospector');
    }

    public function handle(CallIntelligenceService $service): void
    {
        try {
            $service->processCall($this->call);
        } catch (\Exception $e) {
            Log::error('ProcessCallIntelligenceJob failed', [
                'call_id' => $this->call->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
