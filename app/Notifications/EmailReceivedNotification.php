<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\EmailMessage;
use App\Models\User;
use Illuminate\Notifications\Notification;

class EmailReceivedNotification extends Notification
{
    public string $pushCategory = 'email_messages';

    public function __construct(public EmailMessage $message) {}

    /**
     * Only send database/broadcast/push notifications for emails from known users.
     * External senders (vendors, paint stores, etc.) only appear in the email tab.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $this->senderIsKnownUser()) {
            return [];
        }

        $channels = [];

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    /**
     * Check if the email sender matches a known user in the system.
     */
    private function senderIsKnownUser(): bool
    {
        return User::where('email', $this->message->from_address)->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $sender = $this->message->from_name ?? $this->message->from_address;

        return [
            'type' => 'email_received',
            'email_message_id' => $this->message->id,
            'thread_id' => $this->message->email_thread_id,
            'from_name' => $this->message->from_name,
            'from_address' => $this->message->from_address,
            'subject' => $this->message->subject,
            'message' => "New email from {$sender}",
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: $this->message->from_name ?? $this->message->from_address,
            body: $this->message->subject,
            url: "/email?threadId={$this->message->email_thread_id}",
            tag: "email-thread-{$this->message->email_thread_id}",
        );
    }
}
