<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TerritoryScheduleAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'jobs';

    public function __construct(
        public Schedule $schedule
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $territory = $this->schedule->territory;

        return [
            'schedule_id' => $this->schedule->id,
            'territory_id' => $territory?->id,
            'territory_name' => $territory?->name,
            'date' => $this->schedule->date->format('M j, Y'),
            'start_time' => $this->schedule->start_time,
            'meet_address' => $this->schedule->meet_address,
            'meet_url' => $this->schedule->getMeetUpUrl(),
            'scheduled_by' => $this->schedule->createdBy?->name,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $territory = $this->schedule->territory;
        $date = $this->schedule->date->format('M j, Y');

        return new WebPushMessage(
            title: 'Territory Schedule Assigned',
            body: "You've been assigned to {$territory?->name} on {$date}",
            url: $this->schedule->getMeetUpUrl() ?? '/my/schedule',
            tag: "schedule-{$this->schedule->id}",
        );
    }
}
