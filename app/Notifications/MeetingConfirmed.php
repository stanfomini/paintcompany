<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\Concerns\ResolvesUserPortalUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingConfirmed extends Notification implements ShouldQueue
{
    use Queueable, ResolvesUserPortalUrl;

    public string $pushCategory = 'general';

    public function __construct(
        public Activity $activity,
        public ?User $confirmedBy = null,
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
        $typeBadge = $this->activity->meeting_type === 'virtual' ? 'Virtual' : 'In Person';

        $message = (new MailMessage)
            ->subject(__('Meeting Confirmed: :title', ['title' => $this->activity->title]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $notifiable->name]))
            ->line(__('All parties have confirmed the meeting.'))
            ->line('**'.__('Meeting').':** '.$this->activity->title)
            ->line('**'.__('Type').':** '.__($typeBadge))
            ->line('**'.__('Date & Time').':** '.$this->activity->due_at->format('l, F j, Y \a\t g:i A'));

        if ($this->activity->isVirtualMeeting() && $this->activity->videoCall) {
            $joinUrl = $this->getVideoCallUrl($notifiable);
            $message->line('**'.__('Video Call').':** '.$joinUrl)
                ->action(__('Join Video Call'), $joinUrl);
        } else {
            $message->action(__('View Meeting Details'), $this->getEstimateUrl($notifiable));
        }

        $message->line(__('You will receive a reminder 1 hour before the meeting.'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activity_id' => $this->activity->id,
            'title' => $this->activity->title,
            'meeting_type' => $this->activity->meeting_type,
            'due_at' => $this->activity->due_at?->toIso8601String(),
            'deal_id' => $this->activity->subject_type === Deal::class ? $this->activity->subject_id : null,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Meeting Confirmed',
            body: "{$this->activity->title} is confirmed",
            url: '/dashboard',
            tag: "meeting-{$this->activity->id}",
        );
    }
}
