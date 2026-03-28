<?php

namespace App\Notifications;

use App\Models\LiveEvent;
use App\Models\LiveEventGuest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LiveEventGuestInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public LiveEvent $event,
        public LiveEventGuest $guest,
        public string $guestLink,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hostName = $this->event->host?->name ?? 'our team';

        $message = (new MailMessage)
            ->subject(__("You're invited to join: :title", ['title' => $this->event->title]))
            ->greeting(__('Hello :name!', ['name' => $this->guest->name]))
            ->line(__("You've been invited to be a guest on a live event hosted by :host.", [
                'host' => $hostName,
            ]))
            ->line('**'.$this->event->title.'**');

        if ($this->event->scheduled_at) {
            $message->line(__('Scheduled: :date', ['date' => $this->event->scheduled_at->format('M j, Y g:i A')]));
        }

        if ($this->guest->instructions) {
            $message->line(__('Instructions from the host:'))
                ->line('> '.$this->guest->instructions);
        }

        $message->action(__('Open Green Room'), $this->guestLink)
            ->line(__('Click the button above to enter the green room. You\'ll be able to preview the stream and test your camera before going live.'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'live_event_id' => $this->event->id,
            'guest_id' => $this->guest->id,
        ];
    }
}
