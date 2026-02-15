<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Models\User;
use App\Notifications\UnreadChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SendUnreadChatEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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

        // Check if recipient has already read the message
        $participant = $this->message->thread->getParticipant($this->recipient);

        if (! $participant) {
            return;
        }

        if ($participant->last_read_at && $participant->last_read_at >= $this->message->created_at) {
            return;
        }

        // Throttle: 1 email per thread per user per hour
        $cacheKey = "chat_email:{$this->message->thread_id}:{$this->recipient->id}";

        if (Cache::has($cacheKey)) {
            return;
        }

        $this->recipient->notify(new UnreadChatMessage($this->message));
        Cache::put($cacheKey, true, now()->addHour());
    }
}
