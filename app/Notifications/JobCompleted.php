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
                ->subject('Your Project is Complete!')
                ->greeting('Great News!')
                ->line("Your painting project ({$this->job->job_number}) has been completed.")
                ->line('Thank you for choosing us! We hope you love the results.')
                ->action('View Project', url('/jobs/'.$this->job->id));
        }

        return (new MailMessage)
            ->subject("Job Completed: {$this->job->job_number}")
            ->greeting("Job Completed: {$this->job->title}")
            ->line("Job {$this->job->job_number} has been marked as completed.")
            ->line("Customer: {$this->job->getCustomer()?->first_name} {$this->job->getCustomer()?->last_name}")
            ->action('View Job', url('/jobs/'.$this->job->id));
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
            'message' => "Job completed: {$this->job->job_number}",
        ];
    }
}
