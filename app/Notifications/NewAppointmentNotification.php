<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\AppointmentBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class NewAppointmentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'appointments';

    public function __construct(public AppointmentBooking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
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
