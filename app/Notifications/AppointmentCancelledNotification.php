<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\AppointmentBooking;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentCancelledNotification extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'appointments';

    public function __construct(public AppointmentBooking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'wantsEmail')
            ? $notifiable->wantsEmail($this->pushCategory)
            : true) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush')
            && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->booking->getProspectFullName();
        $date = $this->booking->booked_date->format('l, M j, Y');

        return (new MailMessage)
            ->subject(__('Appointment Cancelled'))
            ->line(__(':name cancelled their appointment on :date.', ['name' => $name, 'date' => $date]))
            ->line('**'.__('Reason').':** '.($this->booking->cancellation_reason ?? __('No reason provided')))
            ->action(__('View Dashboard'), url('/dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'prospect_name' => $this->booking->getProspectFullName(),
            'date' => $this->booking->booked_date->toDateString(),
            'time' => $this->booking->booked_start_time,
            'reason' => $this->booking->cancellation_reason,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = $this->booking->getProspectFullName();

        return new WebPushMessage(
            title: __('Appointment Cancelled'),
            body: "{$name} cancelled their appointment",
            url: '/dashboard',
            tag: "booking-cancel-{$this->booking->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $name = $this->booking->getProspectFullName();

        return new SmsMessage(
            body: "{$name} cancelled their appointment.",
            deepLink: url('/dashboard'),
        );
    }
}
