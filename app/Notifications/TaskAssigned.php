<?php

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Activity $activity
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $assignerName = $this->activity->user?->name ?? __('Someone');
        $dueText = $this->activity->due_at
            ? __('Due').': '.$this->activity->due_at->format('M j, Y')
            : __('No due date');

        return (new MailMessage)
            ->subject(__('New Task Assigned: :title', ['title' => $this->activity->title]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':assignerName assigned you a new task:', ['assignerName' => $assignerName]))
            ->line("**{$this->activity->title}**")
            ->line($dueText)
            ->when($this->activity->description, function ($mail) {
                return $mail->line($this->activity->description);
            })
            ->action(__('View Task'), route('planner.index'))
            ->line(__('Thank you for using our application!'));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'activity_id' => $this->activity->id,
            'title' => $this->activity->title,
            'type' => $this->activity->type,
            'due_at' => $this->activity->due_at?->toISOString(),
            'assigned_by' => $this->activity->user_id,
            'assigned_by_name' => $this->activity->user?->name,
        ];
    }
}
