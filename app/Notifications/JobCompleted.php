<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobCompleted extends Notification implements ShouldQueue
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
        if ($notifiable->isCustomer()) {
            return (new MailMessage)
                ->subject(__('Your Project is Complete!'))
                ->greeting(__('Great News!'))
                ->line(__('Your painting project (:number) has been completed.', ['number' => $this->job->job_number]))
                ->line(__('Thank you for choosing us! We hope you love the results.'))
                ->action(__('View Project'), url('/jobs/'.$this->job->id));
        }

        return (new MailMessage)
            ->subject(__('Job Completed: :number', ['number' => $this->job->job_number]))
            ->greeting(__('Job Completed: :title', ['title' => $this->job->title]))
            ->line(__('Job :number has been marked as completed.', ['number' => $this->job->job_number]))
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
            'message' => __('Job completed: :number', ['number' => $this->job->job_number]),
        ];
    }
}
