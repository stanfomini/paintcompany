<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerProposedDate extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Job $job,
        public string $proposedDate
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
        $customerName = $this->job->getCustomer()?->first_name.' '.$this->job->getCustomer()?->last_name;

        return (new MailMessage)
            ->subject(__('Date Proposed: :number', ['number' => $this->job->job_number]))
            ->greeting(__('Date Proposed: :title', ['title' => $this->job->title]))
            ->line(__(':customerName has proposed a new start date for job :number.', ['customerName' => $customerName, 'number' => $this->job->job_number]))
            ->line(__('Proposed date').': '.$this->proposedDate)
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
            'proposed_date' => $this->proposedDate,
            'message' => __('Customer proposed date: :date', ['date' => $this->proposedDate]),
        ];
    }
}
