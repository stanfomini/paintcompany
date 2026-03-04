<?php

namespace App\Notifications;

use App\Models\AppointmentBooking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AppointmentCancelledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AppointmentBooking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
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
}
