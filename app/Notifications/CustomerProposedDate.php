<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Job;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerProposedDate extends Notification implements ShouldQueue
{
    use Queueable, RestrictsToUserTypes;

    /** @var array<string> Only internal users should receive this */
    protected array $allowedUserTypes = ['internal'];

    public string $pushCategory = 'jobs';

    public function __construct(
        public Job $job,
        public string $proposedDate
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        if (! $this->isAllowedRecipient($notifiable)) {
            return [];
        }

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

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'Date Proposed',
            body: "Customer proposed {$this->proposedDate} for job #{$this->job->job_number}",
            url: "/jobs/{$this->job->id}",
            tag: "job-{$this->job->id}",
        );
    }
}
