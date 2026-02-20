<?php

namespace App\Notifications;

use App\Models\Job;
use App\Models\ServiceCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewJobDispatched extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Job $job,
        public ?ServiceCategory $serviceCategory = null
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
        $appName = config('app.name');
        $categoryLabel = $this->serviceCategory ? $this->serviceCategory->name : 'General';

        $message = (new MailMessage)
            ->subject(__('New Job Available — :jobNumber', ['jobNumber' => $this->job->job_number]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->first_name ?? $notifiable->name]))
            ->line(__('A new :category job has been dispatched to you.', ['category' => $categoryLabel]))
            ->line('**'.__('Job').':** '.$this->job->job_number)
            ->line('**'.__('Title').':** '.$this->job->title);

        if ($this->job->scheduled_date) {
            $message->line('**'.__('Scheduled Date').':** '.$this->job->scheduled_date->format('M j, Y'));
        }

        if ($this->job->deal?->property) {
            $message->line('**'.__('Property').':** '.$this->job->deal->property->display_name);
        }

        $message->action(__('View Available Jobs'), route('crew.jobs.available'))
            ->line(__('Please review the job details and accept or decline at your earliest convenience.'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'job_id' => $this->job->id,
            'job_number' => $this->job->job_number,
            'service_category_id' => $this->serviceCategory?->id,
        ];
    }
}
