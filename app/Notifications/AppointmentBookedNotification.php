<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Models\AppointmentBooking;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentBookedNotification extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'appointments';

    public function __construct(public AppointmentBooking $booking) {}

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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;
        $date = $booking->booked_date->format('l, M j, Y');
        $time = $booking->booked_start_time;
        $estimatorName = $booking->estimator?->name ?? 'our estimator';

        return (new MailMessage)
            ->subject(__('Your Appointment is Confirmed'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $booking->prospect_first_name]))
            ->line(__('Your estimate appointment has been confirmed.'))
            ->line('**'.__('Date').':** '.$date)
            ->line('**'.__('Time').':** '.$time)
            ->line('**'.__('Estimator').':** '.$estimatorName)
            ->action(__('View Appointment'), route('booking.confirmed', $booking->booking_token))
            ->line(__('Need to cancel? Click the link below:'))
            ->line('[Cancel Appointment]('.route('booking.cancel', $booking->booking_token).')');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'date' => $this->booking->booked_date->toDateString(),
            'time' => $this->booking->booked_start_time,
        ];
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $date = $this->booking->booked_date->format('M j, Y');
        $time = $this->booking->booked_start_time;

        return new SmsMessage(
            body: "Your estimate appointment is confirmed for {$date} at {$time}.",
            deepLink: route('booking.confirmed', $this->booking->booking_token),
        );
    }
}
