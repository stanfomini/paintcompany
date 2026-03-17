<?php

namespace App\Jobs;

use App\Ai\Agents\EstimateBuilderAgent;
use App\Models\Estimate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessEstimateAiChat implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  array<int, array{role: string, content: string}>  $history  Prior conversation messages
     */
    public function __construct(
        public int $estimateId,
        public string $cacheKey,
        public string $userMessage,
        public array $history = [],
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        try {
            $estimate = Estimate::findOrFail($this->estimateId);

            $agent = new EstimateBuilderAgent($estimate);
            $agent->withHistory($this->history);

            $response = $agent->prompt($this->userMessage);
            $responseText = (string) $response;

            Cache::put($this->cacheKey, [
                'status' => 'complete',
                'response' => $responseText,
            ], now()->addMinutes(10));
        } catch (\Throwable $e) {
            Log::error('AI Estimate Builder job failed', [
                'estimate_id' => $this->estimateId,
                'message' => $this->userMessage,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Give user a friendlier message for known error types
            $userMessage = str_contains($e->getMessage(), 'XAI Error')
                ? 'The AI service returned an error. Please try again in a moment.'
                : 'AI error: '.$e->getMessage();

            Cache::put($this->cacheKey, [
                'status' => 'error',
                'response' => $userMessage,
            ], now()->addMinutes(10));
        }
    }

    /**
     * Handle job failure (e.g., timeout).
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('AI Estimate Builder job failed (external)', [
            'estimate_id' => $this->estimateId,
            'message' => $this->userMessage,
            'error' => $exception?->getMessage(),
        ]);

        Cache::put($this->cacheKey, [
            'status' => 'error',
            'response' => 'The AI request timed out. Please try a simpler prompt.',
        ], now()->addMinutes(10));
    }
}
