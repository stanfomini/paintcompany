<?php

namespace App\Notifications;

use App\Models\LiveEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LiveEventStarted extends Notification implements ShouldQueue
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
        $hostName = $this->event->host?->name ?? 'our team';
        $url = url("/live/{$this->event->slug}");

        return (new MailMessage)
            ->subject(__(':title is LIVE now!', ['title' => $this->event->title]))
            ->greeting(__('It\'s showtime!'))
            ->line(__(':title is now live, hosted by :host.', [
                'title' => $this->event->title,
                'host' => $hostName,
            ]))
            ->action(__('Join Now'), $url)
            ->line(__('Don\'t miss out - join now to watch live and ask questions.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'live_event_id' => $this->event->id,
            'title' => $this->event->title,
        ];
    }
}
