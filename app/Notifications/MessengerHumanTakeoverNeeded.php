<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\MessengerConversation;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MessengerHumanTakeoverNeeded extends Notification implements ShouldQueue
{
    use Queueable, RestrictsToUserTypes;

    /** @var array<string> Only internal users should receive this */
    protected array $allowedUserTypes = ['internal'];

    public string $pushCategory = 'messenger';

    public function __construct(
        public MessengerConversation $conversation
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $this->isAllowedRecipient($notifiable)) {
            return [];
        }

        $channels = [];

        if (method_exists($notifiable, 'wantsEmail')) {
            if ($notifiable->wantsEmail($this->pushCategory)) {
                $channels[] = 'mail';
            }
        } else {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $collected = $this->conversation->getCollectedData();
        $collectedSummary = collect($collected)
            ->map(fn ($v, $k) => str_replace(['customer.', 'deal.'], '', $k).": {$v}")
            ->implode(', ');

        $message = (new MailMessage)
            ->subject('Messenger Conversation Needs Human Attention')
            ->greeting('Human Takeover Needed')
            ->line('A Facebook Messenger conversation needs your attention.');

        if (! empty($collectedSummary)) {
            $message->line("**Bot collected:** {$collectedSummary}");
        }

        $chatUrl = $this->conversation->thread_id
            ? url("/chat/{$this->conversation->thread_id}")
            : url('/chat');

        $message->action('View Conversation', $chatUrl)
            ->line('The bot was unable to complete the conversation. Please take over.');

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'messenger_human_takeover',
            'conversation_id' => $this->conversation->id,
            'thread_id' => $this->conversation->thread_id,
            'message' => 'A Messenger conversation needs your attention',
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Messenger: Human Takeover Needed',
            body: 'A Facebook Messenger conversation needs your attention',
            url: $this->conversation->thread_id ? "/chat/{$this->conversation->thread_id}" : '/chat',
            tag: "messenger-takeover-{$this->conversation->id}",
        );
    }
}
