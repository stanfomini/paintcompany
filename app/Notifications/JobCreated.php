<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobCreated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Job $job
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
        return (new MailMessage)
            ->subject(__('New Job Created: :number', ['number' => $this->job->job_number]))
            ->greeting(__('New Job: :title', ['title' => $this->job->title]))
            ->line(__('A new job (:number) has been created from an accepted estimate.', ['number' => $this->job->job_number]))
            ->line(__('Customer').': '.$this->job->getCustomer()?->first_name.' '.$this->job->getCustomer()?->last_name)
            ->action(__('View Job'), url('/jobs/'.$this->job->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'job_id' => $this->job->id,
            'job_number' => $this->job->job_number,
            'title' => $this->job->title,
            'message' => __('New job created: :number', ['number' => $this->job->job_number]),
        ];
    }
}
