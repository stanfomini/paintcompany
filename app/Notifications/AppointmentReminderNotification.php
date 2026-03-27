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

class AppointmentReminderNotification extends Notification implements ShouldQueue
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

        if (method_exists($notifiable, 'wantsPush')
            && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
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

        $message = (new MailMessage)
            ->subject(__('Appointment Reminder - Tomorrow'))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $booking->prospect_first_name]))
            ->line(__('This is a friendly reminder about your estimate appointment tomorrow.'))
            ->line('**'.__('Date').':** '.$date)
            ->line('**'.__('Time').':** '.$time);

        if ($booking->prospect_address) {
            $message->line('**'.__('Address').':** '.$booking->prospect_address);
        }

        $message->line(__('Need to cancel? Click the link below:'))
            ->action(__('Cancel Appointment'), route('booking.cancel', $booking->booking_token));

        return $message;
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $date = $this->booking->booked_date->format('M j');
        $time = $this->booking->booked_start_time;

        return new WebPushMessage(
            title: __('Appointment Reminder'),
            body: "Upcoming appointment on {$date} at {$time}",
            url: '/dashboard',
            tag: "booking-reminder-{$this->booking->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $time = $this->booking->booked_start_time;

        return new SmsMessage(
            body: "Reminder: Your estimate appointment is tomorrow at {$time}. Need to cancel?",
            deepLink: route('booking.cancel', $this->booking->booking_token),
        );
    }
}
