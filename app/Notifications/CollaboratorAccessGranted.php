<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CollaboratorAccessGranted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Deal $deal, public User $inviter) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__("You've been invited to collaborate on a project"))
            ->greeting(__('Hello!'))
            ->line(__(":name has shared their painting project ':title' with you.", ['name' => $this->inviter->name, 'title' => $this->deal->title]))
            ->line(__('You can now view and participate in this project. Access expires in 30 days.'))
            ->action(__('View Project'), route('customer.dashboard'))
            ->line(__('If you have any questions, reply to this email.'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'deal_id' => $this->deal->id,
            'inviter_id' => $this->inviter->id,
        ];
    }
}
