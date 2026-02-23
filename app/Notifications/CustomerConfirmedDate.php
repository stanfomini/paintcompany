<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerConfirmedDate extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'jobs';

    public function __construct(
        public Job $job
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (method_exists($notifiable, 'wantsEmail')) {
            if ($notifiable->wantsEmail($this->pushCategory)) {
                $channels[] = 'mail';
            }
        } else {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $customerName = $this->job->getCustomer()?->first_name.' '.$this->job->getCustomer()?->last_name;

        return (new MailMessage)
            ->subject(__('Date Confirmed: :number', ['number' => $this->job->job_number]))
            ->greeting(__('Date Confirmed: :title', ['title' => $this->job->title]))
            ->line(__(':customerName has confirmed the scheduled date for job :number.', ['customerName' => $customerName, 'number' => $this->job->job_number]))
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
            'message' => __('Customer confirmed date for :number', ['number' => $this->job->job_number]),
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Date Confirmed',
            body: "Customer confirmed the date for job #{$this->job->job_number}",
            url: "/jobs/{$this->job->id}",
            tag: "job-{$this->job->id}",
        );
    }
}
