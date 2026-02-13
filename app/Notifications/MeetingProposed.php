<?php

namespace App\Notifications;

use App\Models\Activity;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MeetingProposed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Activity $activity,
        public User $proposedBy,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name', 'Apex Negotiations');
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

        $message->action(__('Confirm Meeting'), $this->getConfirmUrl($notifiable))
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
        ];
    }

    private function getConfirmUrl(object $notifiable): string
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
