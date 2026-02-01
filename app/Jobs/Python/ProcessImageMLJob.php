<?php

namespace App\Jobs\Python;

use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessImageMLJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param  array<string>  $operations  Operations to perform (enhance, detect_substrate, recolor, etc.)
     */
    public function __construct(
        public string $imageUrl,
        public array $operations,
        public array $options = [],
        public ?string $outputPath = null,
        public ?string $callbackUrl = null,
        public array $metadata = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PythonBridgeService $python): void
    {
        Log::info('Starting image ML job', [
            'image_url' => $this->imageUrl,
            'operations' => $this->operations,
        ]);

        try {
            $result = $python->processImage(
                $this->imageUrl,
                $this->operations,
                $this->options
            );

            if ($result['success'] ?? false) {
                Log::info('Image ML job completed', [
                    'operations' => $result['operations_applied'] ?? [],
                    'metadata' => $result['metadata'] ?? [],
                ]);

                // Save the result image if output path is provided
                if ($this->outputPath && isset($result['result_base64'])) {
                    $savedPath = $python->saveBase64Image(
                        $result['result_base64'],
                        $this->outputPath
                    );

                    Log::info('Saved processed image', ['path' => $savedPath]);
                }

                // Send callback if provided
                if ($this->callbackUrl) {
                    $this->sendCallback($result);
                }
            } else {
                Log::warning('Image ML job failed', [
                    'image_url' => $this->imageUrl,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Image ML job exception', [
                'image_url' => $this->imageUrl,
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
                'job_type' => 'process_image_ml',
                'image_url' => $this->imageUrl,
                'operations' => $this->operations,
                'result' => $result,
                'output_path' => $this->outputPath,
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
        Log::error('Image ML job failed permanently', [
            'image_url' => $this->imageUrl,
            'operations' => $this->operations,
            'error' => $exception?->getMessage(),
        ]);
    }
}
