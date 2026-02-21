<?php

namespace App\Notifications;

use App\Models\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class TerritoryScheduleAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Schedule $schedule
    ) {}

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
}
