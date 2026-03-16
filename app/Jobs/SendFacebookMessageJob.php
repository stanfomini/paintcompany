<?php

namespace App\Jobs;

use App\Models\ChatMessage;
use App\Services\FacebookMessengerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendFacebookMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    /**
     * @param  string  $psid  Facebook Page-Scoped ID of the recipient.
     * @param  string  $text  The message text to send.
     * @param  array<int, array{title: string, payload: string}>|null  $quickReplies  Optional quick reply buttons.
     * @param  int|null  $chatMessageId  Optional ChatMessage ID to update with facebook_message_id.
     * @param  string|null  $mediaUrl  Optional image URL to send as attachment.
     */
    public function __construct(
        public string $psid,
        public string $text,
        public ?array $quickReplies = null,
        public ?int $chatMessageId = null,
        public ?string $mediaUrl = null,
    ) {
        $this->onConnection('redis');
    }

    public function handle(FacebookMessengerService $messenger): void
    {
        if ($this->mediaUrl) {
            $result = $messenger->sendImageMessage($this->psid, $this->mediaUrl);
        } elseif ($this->quickReplies) {
            $result = $messenger->sendQuickReply($this->psid, $this->text, $this->quickReplies);
        } else {
            $result = $messenger->sendTextMessage($this->psid, $this->text);
        }

        // Store the Facebook message ID in the ChatMessage metadata
        if ($result['success'] && $this->chatMessageId && $result['message_id']) {
            $chatMessage = ChatMessage::find($this->chatMessageId);

            if ($chatMessage) {
                $metadata = $chatMessage->metadata ?? [];
                $metadata['facebook_message_id'] = $result['message_id'];
                $chatMessage->update(['metadata' => $metadata]);
            }
        }

        if (! $result['success']) {
            Log::warning('SendFacebookMessageJob: Failed to send message', [
                'psid' => $this->psid,
                'error' => $result['error'],
                'chat_message_id' => $this->chatMessageId,
            ]);
        }
    }
}
