<?php

namespace App\Notifications;

use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SchedulePublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Schedule $schedule,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->schedule->date->format('M j, Y');
        $startTime = $this->schedule->start_time;
        $endTime = $this->schedule->end_time;

        $message = (new MailMessage)
            ->subject("New schedule: {$date}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("You have been scheduled to work on **{$date}**.")
            ->line("**Time:** {$startTime} - {$endTime}");

        if ($this->schedule->notes) {
            $message->line("**Notes:** {$this->schedule->notes}");
        }

        $message->action('View My Schedule', route('my.schedule'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'schedule_id' => $this->schedule->id,
            'date' => $this->schedule->date->toISOString(),
            'start_time' => $this->schedule->start_time,
            'end_time' => $this->schedule->end_time,
        ];
    }
}
