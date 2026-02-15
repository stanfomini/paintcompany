<?php

namespace App\Notifications;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class UnreadChatMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ChatMessage $message) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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

        $mail->line(__('You received this email because the message was unread for 5 minutes.'));

        return $mail;
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
