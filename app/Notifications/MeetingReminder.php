<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Activity $activity,
        public ?User $actionBy = null,
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
            $message->action(__('View Details'), $this->getUrl($notifiable));
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
        ];
    }

    private function getVideoCallUrl(object $notifiable): string
    {
        if ($this->activity->videoCall) {
            return route('video-call.test', $this->activity->videoCall->room_id);
        }

        return $this->getUrl($notifiable);
    }

    private function getUrl(object $notifiable): string
    {
        if ($notifiable->isCustomer() && $this->activity->estimate_id) {
            return route('customer.estimates.show', $this->activity->estimate_id);
        }

        if ($notifiable->isAgent() && $this->activity->estimate_id) {
            return route('agent.estimates.show', $this->activity->estimate_id);
        }

        if ($this->activity->estimate_id) {
            return route('estimates.show', $this->activity->estimate_id);
        }

        return url('/');
    }
}
