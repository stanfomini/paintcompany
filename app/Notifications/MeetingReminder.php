<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Notifications\Concerns\ResolvesUserPortalUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminder extends Notification implements ShouldQueue
{
    use Queueable, ResolvesUserPortalUrl;

    public function __construct(
        public Activity $activity,
        public ?User $actionBy = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'broadcast'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $typeBadge = $this->activity->meeting_type === 'virtual' ? 'Virtual' : 'In Person';

        $message = (new MailMessage)
            ->subject(__('Reminder: :title in 1 hour', ['title' => $this->activity->title]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $notifiable->name]))
            ->line(__('Your meeting is coming up in about 1 hour.'))
            ->line('**'.__('Meeting').':** '.$this->activity->title)
            ->line('**'.__('Type').':** '.__($typeBadge))
            ->line('**'.__('Date & Time').':** '.$this->activity->due_at->format('l, F j, Y \a\t g:i A'));

        if ($this->activity->isVirtualMeeting() && $this->activity->videoCall) {
            $message->action(__('Join Video Call'), $this->getVideoCallUrl($notifiable));
        } else {
            $message->action(__('View Details'), $this->getEstimateUrl($notifiable));
        }

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
}
