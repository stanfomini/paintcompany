<?php

namespace App\Jobs;

use App\Models\CallLog;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\ChatService;
use App\Services\PythonBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscribeVoicemailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        public int $callLogId
    ) {}

    public function handle(PythonBridgeService $pythonBridge): void
    {
        $callLog = CallLog::find($this->callLogId);

        if (! $callLog) {
            Log::warning('TranscribeVoicemailJob: CallLog not found', [
                'call_log_id' => $this->callLogId,
            ]);

            return;
        }

        if (! $callLog->recording_url) {
            Log::info('TranscribeVoicemailJob: No recording URL, skipping', [
                'call_log_id' => $this->callLogId,
            ]);

            return;
        }

        try {
            $result = $pythonBridge->transcribeAudio($callLog->getRawRecordingUrl());

            if (! ($result['success'] ?? false)) {
                Log::warning('TranscribeVoicemailJob: Transcription failed', [
                    'call_log_id' => $this->callLogId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                throw new \RuntimeException($result['error'] ?? 'Transcription failed');
            }

            $transcription = $result['text'] ?? '';

            $callLog->update([
                'voicemail_transcription' => $transcription,
            ]);

            Log::info('TranscribeVoicemailJob: Transcription saved', [
                'call_log_id' => $this->callLogId,
                'text_length' => strlen($transcription),
            ]);

            // Surface voicemail in communications hub via chat thread
            $this->notifyViaChatThread($callLog, $transcription);
        } catch (\Exception $e) {
            Log::error('TranscribeVoicemailJob: Exception', [
                'call_log_id' => $this->callLogId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create a chat message in the customer's thread to surface the voicemail.
     */
    private function notifyViaChatThread(CallLog $callLog, string $transcription): void
    {
        if (empty($transcription)) {
            return;
        }

        // Find the customer associated with this call
        $customer = $callLog->customer;

        if (! $customer || ! $customer->user_id) {
            return;
        }

        $customerUser = User::find($customer->user_id);

        if (! $customerUser) {
            return;
        }

        // Find an existing thread for this customer, or skip if none exists
        $thread = ChatThread::query()
            ->where('customer_id', $customer->id)
            ->whereIn('type', [ChatThread::TYPE_SUPPORT, ChatThread::TYPE_DEAL])
            ->latest('last_message_at')
            ->first();

        if (! $thread) {
            return;
        }

        // Use a system-style message from the first participant (internal user)
        $internalUser = User::where('type', 'internal')
            ->where('is_active', true)
            ->first();

        if (! $internalUser) {
            return;
        }

        $fromNumber = $callLog->from_number ?? 'Unknown';
        $messageContent = "Voicemail from {$fromNumber}: {$transcription}";

        try {
            $chatService = app(ChatService::class);
            $chatService->sendMessage(
                thread: $thread,
                sender: $internalUser,
                content: $messageContent,
                options: [
                    'metadata' => [
                        'type' => 'voicemail',
                        'call_log_id' => $callLog->id,
                    ],
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('TranscribeVoicemailJob: Failed to create chat message', [
                'call_log_id' => $callLog->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('TranscribeVoicemailJob: Failed permanently', [
            'call_log_id' => $this->callLogId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
