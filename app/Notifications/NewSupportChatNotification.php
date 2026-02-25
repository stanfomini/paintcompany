<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\GuestChatSession;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSupportChatNotification extends Notification
{
    use Queueable, RestrictsToUserTypes;

    /** @var array<string> Only internal users should receive this */
    protected array $allowedUserTypes = ['internal'];

    public string $pushCategory = 'chat';

    public function __construct(public GuestChatSession $session) {}

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
        return (new MailMessage)
            ->subject('New Support Chat from '.($this->session->name ?: $this->session->email))
            ->line('A visitor has started a support chat.')
            ->line('Email: '.$this->session->email)
            ->line('Name: '.($this->session->name ?: 'Not provided'))
            ->action('View Chat', url('/chat'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'new_support_chat',
            'session_id' => $this->session->id,
            'email' => $this->session->email,
            'name' => $this->session->name,
            'message' => 'New support chat from '.($this->session->name ?: $this->session->email),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = $this->session->name ?: $this->session->email;

        return new WebPushMessage(
            title: 'New Support Chat',
            body: "{$name} started a support conversation",
            url: '/chat',
            tag: "chat-support-{$this->session->id}",
        );
    }
}
