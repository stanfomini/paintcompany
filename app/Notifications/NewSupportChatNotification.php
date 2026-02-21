<?php

namespace App\Notifications;

use App\Models\GuestChatSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewSupportChatNotification extends Notification
{
    use Queueable;

    public function __construct(public GuestChatSession $session) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
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
}
