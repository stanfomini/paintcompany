<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class UnreadChatMessage extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'chat';

    public function __construct(public ChatMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->message->loadMissing(['user', 'thread.deal']);

        $sender = $this->message->user;
        $thread = $this->message->thread;
        $senderName = $sender?->name ?? __('Someone');
        $preview = Str::limit($this->message->content, 200);

        $subject = $thread->type === ChatThread::TYPE_DEAL && $thread->deal
            ? __('New message in :title', ['title' => $thread->deal->title])
            : __('New message from :name', ['name' => $senderName]);

        $chatUrl = $this->getChatUrl($notifiable, $thread);

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name]))
            ->line(__('You have an unread message from **:sender**:', ['sender' => $senderName]));

        if ($thread->type === ChatThread::TYPE_DEAL && $thread->deal) {
            $mail->line('**'.__('Deal').':** '.$thread->deal->title);
        }

        $mail->line('> '.$preview);

        if ($chatUrl) {
            $mail->action(__('View Conversation'), $chatUrl);
        }

        $mail->line(__('You received this email because you have an unread message.'));

        return $mail;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $this->message->loadMissing(['user', 'thread']);
        $senderName = $this->message->user?->name ?? 'Someone';

        return [
            'type' => 'unread_chat_message',
            'message_id' => $this->message->id,
            'thread_id' => $this->message->thread_id,
            'sender_name' => $senderName,
            'preview' => Str::limit($this->message->content, 100),
            'message' => "New message from {$senderName}",
        ];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $this->message->loadMissing(['user']);
        $senderName = $this->message->user?->name ?? 'Someone';
        $preview = Str::limit($this->message->content, 100);

        return new SmsMessage(
            body: "Message from {$senderName}: {$preview}",
            deepLink: $this->getChatUrl($notifiable, $this->message->thread),
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $this->message->loadMissing(['user', 'thread']);
        $senderName = $this->message->user?->name ?? 'Someone';
        $preview = Str::limit($this->message->content, 80);

        return new WebPushMessage(
            title: "Message from {$senderName}",
            body: $preview,
            url: '/chat',
            tag: "chat-{$this->message->thread_id}",
        );
    }

    private function getChatUrl(object $notifiable, ChatThread $thread): ?string
    {
        if ($notifiable->isInternal()) {
            return route('chat.show', $thread);
        }

        // Map user type to route name
        $routeMap = [
            'agent' => 'agent.chat.show',
            'customer' => 'customer.chat.show',
            'crew' => 'crew.chat.show',
        ];

        $routeName = $routeMap[$notifiable->type] ?? 'chat.show';

        try {
            return route($routeName, $thread);
        } catch (\Throwable) {
            return null;
        }
    }
}
