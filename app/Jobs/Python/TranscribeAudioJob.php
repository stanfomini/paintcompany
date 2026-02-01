<?php

namespace App\Jobs\Python;

use App\Services\PythonBridgeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscribeAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param  string  $model  Whisper model size (tiny, base, small, medium, large)
     */
    public function __construct(
        public string $audioUrl,
        public string $model = 'base',
        public ?string $language = null,
        public ?string $callbackUrl = null,
        public array $metadata = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PythonBridgeService $python): void
    {
        Log::info('Starting transcription job', [
            'audio_url' => $this->audioUrl,
            'model' => $this->model,
            'language' => $this->language,
        ]);

        try {
            $result = $python->transcribeAudio(
                $this->audioUrl,
                $this->model,
                $this->language
            );

            if ($result['success'] ?? false) {
                Log::info('Transcription job completed', [
                    'audio_url' => $this->audioUrl,
                    'language' => $result['language'] ?? null,
                    'duration' => $result['duration'] ?? null,
                    'text_length' => strlen($result['text'] ?? ''),
                ]);

                // Send callback if provided
                if ($this->callbackUrl) {
                    $this->sendCallback($result);
                }
            } else {
                Log::warning('Transcription job failed', [
                    'audio_url' => $this->audioUrl,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Transcription job exception', [
                'audio_url' => $this->audioUrl,
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
                'job_type' => 'transcribe_audio',
                'audio_url' => $this->audioUrl,
                'model' => $this->model,
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
        Log::error('Transcription job failed permanently', [
            'audio_url' => $this->audioUrl,
            'error' => $exception?->getMessage(),
        ]);
    }
}
