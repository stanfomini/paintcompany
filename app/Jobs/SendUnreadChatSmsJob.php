<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\SmsSession;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SendUnreadChatSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    public function __construct(
        public ChatMessage $message,
        public User $recipient
    ) {}

    public function handle(): void
    {
        // Skip if message was deleted
        if (! $this->message->exists) {
            return;
        }

        // Skip system messages
        if ($this->message->isSystem()) {
            return;
        }

        // Skip internal messages for non-internal users
        if ($this->message->is_internal && ! $this->recipient->isInternal()) {
            return;
        }

        // Don't SMS internal users
        if ($this->recipient->isInternal()) {
            return;
        }

        // Check if recipient wants SMS for chat
        if (! $this->recipient->wantsSms('chat')) {
            return;
        }

        // Check if recipient has already read the message
        $participant = $this->message->thread->getParticipant($this->recipient);

        if (! $participant) {
            return;
        }

        if ($participant->last_read_at && $participant->last_read_at >= $this->message->created_at) {
            return;
        }

        // Throttle: 1 SMS per thread per user per hour
        $cacheKey = "chat_sms:{$this->message->thread_id}:{$this->recipient->id}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $phone = $this->recipient->routeNotificationForTwilio();

        if (! $phone) {
            return;
        }

        $twilio = app(TwilioService::class);
        $senderName = $this->message->user?->name ?? 'Someone';
        $preview = Str::limit($this->message->content, 100);
        $body = "Message from {$senderName}: {$preview}";
        $body = $twilio->prependConsentIfNeeded($this->recipient->id, $body);

        $result = $twilio->sendSms($phone, $body);

        if ($result['success']) {
            Cache::put($cacheKey, true, now()->addHour());

            // Create/update SMS session for reply routing
            SmsSession::updateOrCreate(
                [
                    'user_id' => $this->recipient->id,
                    'thread_id' => $this->message->thread_id,
                ],
                [
                    'phone_number' => $phone,
                    'last_message_id' => $this->message->id,
                    'expires_at' => now()->addHours(24),
                ]
            );
        }
    }
}
