<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\SmsSession;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends a reply from an internal user in an SMS inbound thread
 * back to the originating phone number via Twilio.
 */
class SendSmsThreadReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        public ChatMessage $message,
        public ChatThread $thread
    ) {}

    public function handle(): void
    {
        // Skip if message was deleted
        if (! $this->message->exists) {
            return;
        }

        // Only process SMS inbound threads
        if ($this->thread->source !== ChatThread::SOURCE_SMS_INBOUND) {
            return;
        }

        // Skip system messages
        if ($this->message->isSystem()) {
            return;
        }

        // Find the originating phone number from active SMS sessions on this thread
        // We want sessions where user_id IS NULL (guest SMS sender) or
        // where the user_id differs from the message sender (reply to the other party)
        $session = SmsSession::where('thread_id', $this->thread->id)
            ->where(function ($q) {
                $q->whereNull('user_id')
                    ->orWhere('user_id', '!=', $this->message->user_id);
            })
            ->latest()
            ->first();

        if (! $session) {
            Log::info('SendSmsThreadReplyJob: no SMS session found for thread', [
                'thread_id' => $this->thread->id,
            ]);

            return;
        }

        $phone = $session->phone_number;

        if (! $phone) {
            return;
        }

        // Throttle: max 1 SMS reply per thread per 30 seconds (prevent rapid-fire)
        $cacheKey = "sms_reply:{$this->thread->id}:{$this->message->id}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $twilio = app(TwilioService::class);
        $senderName = $this->message->user?->name ?? 'Team';
        $preview = Str::limit($this->message->content, 250);
        $body = "{$senderName}: {$preview}";

        // If there's a user associated with the session, prepend consent if needed
        if ($session->user_id) {
            $body = $twilio->prependConsentIfNeeded($session->user_id, $body);
        }

        $this->message->load('attachments');
        $imageAttachments = $this->message->attachments->filter(fn ($a) => $a->isImage());

        if ($imageAttachments->isNotEmpty()) {
            $mediaUrls = $imageAttachments->map(fn ($a) => $a->getUrl())->values()->toArray();
            $result = $twilio->sendMms($phone, $body, $mediaUrls);
        } else {
            $result = $twilio->sendSms($phone, $body);
        }

        if ($result['success']) {
            Cache::put($cacheKey, true, now()->addSeconds(30));

            // Extend session expiry
            $session->update([
                'last_message_id' => $this->message->id,
                'expires_at' => now()->addHours(24),
            ]);

            Log::info('SendSmsThreadReplyJob: reply sent', [
                'thread_id' => $this->thread->id,
                'phone' => $phone,
                'message_id' => $this->message->id,
            ]);
        } else {
            Log::error('SendSmsThreadReplyJob: failed to send', [
                'thread_id' => $this->thread->id,
                'phone' => $phone,
                'error' => $result['error'] ?? 'unknown',
            ]);
        }
    }
}
