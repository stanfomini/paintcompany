<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerConfirmedDate extends Notification implements ShouldQueue
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
            ->subject("Date Confirmed: {$this->job->job_number}")
            ->greeting("Date Confirmed: {$this->job->title}")
            ->line("{$this->job->getCustomer()?->first_name} {$this->job->getCustomer()?->last_name} has confirmed the scheduled date for job {$this->job->job_number}.")
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
            'message' => "Customer confirmed date for {$this->job->job_number}",
        ];
    }
}
