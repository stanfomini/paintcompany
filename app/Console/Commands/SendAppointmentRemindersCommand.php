<?php

namespace App\Console\Commands;

use App\Models\AppointmentBooking;
use App\Notifications\AppointmentReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendAppointmentRemindersCommand extends Command
{
    protected $signature = 'booking:send-reminders';

    protected $description = 'Send appointment reminders for tomorrow\'s bookings';

    public function handle(): int
    {
        $bookings = AppointmentBooking::needsReminder()->get();

        $count = 0;

        foreach ($bookings as $booking) {
            $notifiable = $booking->customer ?? null;

            if ($notifiable) {
                $notifiable->notify(new AppointmentReminderNotification($booking));
            } elseif ($booking->prospect_email) {
                Notification::route('mail', $booking->prospect_email)
                    ->notify(new AppointmentReminderNotification($booking));
            }

            // Also notify the estimator
            if ($booking->estimator) {
                $booking->estimator->notify(new AppointmentReminderNotification($booking));
            }

            $booking->update(['reminder_sent_at' => now()]);
            $count++;
        }

        $this->info("Sent {$count} appointment reminders.");

        return self::SUCCESS;
    }
}
