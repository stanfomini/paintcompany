<?php

namespace App\Jobs\Python;

use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScrapeListingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 180;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $url,
        public ?string $source = null,
        public ?string $callbackUrl = null,
        public array $metadata = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PythonBridgeService $python): void
    {
        Log::info('Starting scrape job', [
            'url' => $this->url,
            'source' => $this->source,
        ]);

        try {
            $result = $python->scrapeListing($this->url, $this->source);

            if ($result['success'] ?? false) {
                Log::info('Scrape job completed successfully', [
                    'url' => $this->url,
                    'address' => $result['data']['address'] ?? null,
                    'price' => $result['data']['price'] ?? null,
                ]);

                // If a callback URL is provided, send the result
                if ($this->callbackUrl) {
                    $this->sendCallback($result);
                }
            } else {
                Log::warning('Scrape job failed', [
                    'url' => $this->url,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Scrape job exception', [
                'url' => $this->url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send results to a callback URL.
     */
    protected function sendCallback(array $result): void
    {
        try {
            \Illuminate\Support\Facades\Http::post($this->callbackUrl, [
                'job_type' => 'scrape_listing',
                'url' => $this->url,
                'result' => $result,
                'metadata' => $this->metadata,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to send callback', [
                'callback_url' => $this->callbackUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Scrape job failed permanently', [
            'url' => $this->url,
            'error' => $exception?->getMessage(),
        ]);
    }
}
