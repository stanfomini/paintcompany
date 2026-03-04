<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\Job;
use App\Models\ServiceCategory;
use App\Notifications\Concerns\HasSmsChannel;
use App\Notifications\Concerns\RestrictsToUserTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewJobDispatched extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable, RestrictsToUserTypes;

    /** @var array<string> Only crew users should receive this */
    protected array $allowedUserTypes = ['crew'];

    public string $pushCategory = 'jobs';

    public function __construct(
        public Job $job,
        public ?ServiceCategory $serviceCategory = null
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

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
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

    public function toSms(object $notifiable): SmsMessage
    {
        return new SmsMessage(
            body: "New job #{$this->job->job_number} dispatched to you!",
            deepLink: route('crew.jobs.available'),
        );
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        return new WebPushMessage(
            title: 'New Job Dispatched',
            body: "Job #{$this->job->job_number} has been dispatched to you",
            url: '/crew/jobs/available',
            tag: "job-{$this->job->id}",
        );
    }
}
