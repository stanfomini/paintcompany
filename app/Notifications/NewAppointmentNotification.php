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

class NewAppointmentNotification extends Notification implements ShouldQueue
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
            'address' => $this->booking->prospect_address,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->booking->getProspectFullName();
        $date = $this->booking->booked_date->format('l, M j, Y');
        $time = $this->booking->booked_start_time;
        $dealUrl = $this->booking->deal_id ? route('deals.show', $this->booking->deal_id) : url('/');

        return (new MailMessage)
            ->subject(__('New Appointment: :name', ['name' => $name]))
            ->line(__(':name booked an estimate appointment.', ['name' => $name]))
            ->line('**'.__('Date').':** '.$date)
            ->line('**'.__('Time').':** '.$time)
            ->action(__('View Deal'), $dealUrl);
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $name = $this->booking->getProspectFullName();
        $date = $this->booking->booked_date->format('M j');
        $time = $this->booking->booked_start_time;

        return new SmsMessage(
            body: "New appointment: {$name} on {$date} at {$time}.",
            deepLink: $this->booking->deal_id ? url("/deals/{$this->booking->deal_id}") : null,
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = $this->booking->getProspectFullName();
        $date = $this->booking->booked_date->format('M j, Y');
        $time = $this->booking->booked_start_time;
        $dealUrl = $this->booking->deal_id ? '/deals/'.$this->booking->deal_id : '/';

        return new WebPushMessage(
            title: 'New Estimate Appointment',
            body: "{$name} booked an appointment for {$date} at {$time}",
            url: $dealUrl,
            tag: "booking-{$this->booking->id}",
        );
    }
}
