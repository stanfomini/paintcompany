<?php

namespace App\Notifications;

use App\Models\LiveEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LiveEventReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public LiveEvent $event) {}

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
        $scheduledTime = $this->event->scheduled_at->format('g:i A, M j');
        $url = url("/live/{$this->event->slug}");

        return (new MailMessage)
            ->subject(__('Reminder: :title starts in 1 hour!', ['title' => $this->event->title]))
            ->greeting(__('Don\'t forget!'))
            ->line(__(':title is starting soon.', ['title' => $this->event->title]))
            ->line('**'.__('Scheduled Time').':** '.$scheduledTime)
            ->action(__('View Event'), $url)
            ->line(__('We\'ll send you another notification when the event goes live.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'live_event_id' => $this->event->id,
            'title' => $this->event->title,
            'scheduled_at' => $this->event->scheduled_at->toIso8601String(),
        ];
    }
}
