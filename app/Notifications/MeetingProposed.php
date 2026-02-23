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

class MeetingProposed extends Notification implements ShouldQueue
{
    use Queueable, ResolvesUserPortalUrl;

    public string $pushCategory = 'general';

    public function __construct(
        public Activity $activity,
        public User $proposedBy,
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
            ->subject(__('Meeting Proposed: :title', ['title' => $this->activity->title]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $notifiable->name]))
            ->line(__(':name has proposed a meeting.', ['name' => $this->proposedBy->name]))
            ->line('**'.__('Meeting').':** '.$this->activity->title)
            ->line('**'.__('Type').':** '.__($typeBadge))
            ->line('**'.__('Date & Time').':** '.$this->activity->due_at->format('l, F j, Y \a\t g:i A'));

        if ($this->activity->description) {
            $message->line('**'.__('Details').':** '.$this->activity->description);
        }

        $message->line(__('Log in to your account to confirm or decline.'))
            ->action(__('Log In & Confirm Meeting'), $this->getEstimateUrl($notifiable))
            ->line(__('Please confirm or decline this meeting at your earliest convenience.'));

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
            title: 'Meeting Proposed',
            body: "{$this->proposedBy->name} proposed: {$this->activity->title}",
            url: '/dashboard',
            tag: "meeting-{$this->activity->id}",
        );
    }
}
