<?php

namespace App\Jobs;

use App\Models\CallLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCallRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    public function __construct(
        public int $callLogId
    ) {}

    public function handle(): void
    {
        $callLog = CallLog::find($this->callLogId);

        if (! $callLog) {
            Log::warning('ProcessCallRecordingJob: CallLog not found', [
                'call_log_id' => $this->callLogId,
            ]);

            return;
        }

        if (! $callLog->recording_url) {
            Log::info('ProcessCallRecordingJob: No recording URL, skipping', [
                'call_log_id' => $this->callLogId,
            ]);

            return;
        }

        try {
            $recordingUrl = $callLog->getRawRecordingUrl();

            // Twilio recording URLs require Basic Auth (Account SID + Auth Token)
            $response = Http::timeout(60)
                ->withBasicAuth(
                    config('services.twilio.sid'),
                    config('services.twilio.token')
                )
                ->get($recordingUrl);

            if (! $response->successful()) {
                Log::warning('ProcessCallRecordingJob: Failed to download recording', [
                    'call_log_id' => $this->callLogId,
                    'url' => $recordingUrl,
                    'status' => $response->status(),
                ]);

                throw new \RuntimeException("Failed to download recording: HTTP {$response->status()}");
            }

            // Store locally under call-recordings/YYYY/MM/ulid.mp3
            $storagePath = 'call-recordings/'.now()->format('Y/m').'/'.$callLog->ulid.'.mp3';

            Storage::put($storagePath, $response->body());

            // Update the CallLog metadata with the local path
            $metadata = $callLog->metadata ?? [];
            $metadata['local_recording_path'] = $storagePath;
            $callLog->update([
                'metadata' => $metadata,
            ]);

            Log::info('ProcessCallRecordingJob: Recording stored', [
                'call_log_id' => $this->callLogId,
                'path' => $storagePath,
                'size_bytes' => strlen($response->body()),
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessCallRecordingJob: Exception', [
                'call_log_id' => $this->callLogId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('ProcessCallRecordingJob: Failed permanently', [
            'call_log_id' => $this->callLogId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
