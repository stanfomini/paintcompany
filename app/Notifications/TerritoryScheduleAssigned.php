<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Schedule;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TerritoryScheduleAssigned extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'jobs';

    public function __construct(
        public Schedule $schedule
    ) {}

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

    public function toMail(object $notifiable): MailMessage
    {
        $territory = $this->schedule->territory;
        $date = $this->schedule->date->format('l, M j, Y');

        return (new MailMessage)
            ->subject(__('Territory Schedule: :name', ['name' => $territory?->name ?? 'Assignment']))
            ->line(__("You've been assigned to :territory on :date.", ['territory' => $territory?->name, 'date' => $date]))
            ->line('**'.__('Start Time').':** '.$this->schedule->start_time)
            ->line('**'.__('Meet Address').':** '.($this->schedule->meet_address ?? 'TBD'))
            ->action(__('View Schedule'), $this->schedule->getMeetUpUrl() ?? url('/my/schedule'));
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $territory = $this->schedule->territory;
        $date = $this->schedule->date->format('M j');

        return new SmsMessage(
            body: "Assigned to {$territory?->name} on {$date} at {$this->schedule->start_time}.",
            deepLink: $this->schedule->getMeetUpUrl() ?? url('/my/schedule'),
        );
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
