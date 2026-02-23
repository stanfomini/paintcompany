<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SchedulePublished extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'jobs';

    public function __construct(
        public Schedule $schedule,
    ) {}

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

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $date = $this->schedule->date->format('M j, Y');

        return new WebPushMessage(
            title: 'New Schedule Published',
            body: "You are scheduled to work on {$date}",
            url: '/my/schedule',
            tag: "schedule-{$this->schedule->id}",
        );
    }
}
