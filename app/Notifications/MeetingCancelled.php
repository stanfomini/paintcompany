<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Activity $activity,
        public ?User $cancelledBy = null,
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
        $status = $this->activity->meeting_status === 'declined' ? 'Declined' : 'Cancelled';

        $message = (new MailMessage)
            ->subject(__('Meeting :status: :title', ['status' => __($status), 'title' => $this->activity->title]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $notifiable->name]))
            ->line(__('A meeting has been :status.', ['status' => strtolower(__($status))]));

        if ($this->cancelledBy) {
            $message->line(__(':status by: :name', ['status' => __($status), 'name' => $this->cancelledBy->name]));
        }

        $message->line('**'.__('Meeting').':** '.$this->activity->title)
            ->line('**'.__('Was Scheduled For').':** '.$this->activity->due_at->format('l, F j, Y \a\t g:i A'));

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
            'meeting_status' => $this->activity->meeting_status,
            'cancelled_by' => $this->cancelledBy?->id,
        ];
    }
}
