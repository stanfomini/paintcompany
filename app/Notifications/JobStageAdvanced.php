<?php

namespace App\Notifications;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JobStageAdvanced extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Job $job,
        public string $fromStage,
        public string $toStage
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
        $fromLabel = Job::STAGES[$this->fromStage] ?? $this->fromStage;
        $toLabel = Job::STAGES[$this->toStage] ?? $this->toStage;

        return (new MailMessage)
            ->subject(__('Job Updated: :number', ['number' => $this->job->job_number]))
            ->greeting(__('Job Updated: :title', ['title' => $this->job->title]))
            ->line(__('Job :number has advanced from :from to :to.', ['number' => $this->job->job_number, 'from' => $fromLabel, 'to' => $toLabel]))
            ->action(__('View Job'), url('/jobs/'.$this->job->id));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $fromLabel = Job::STAGES[$this->fromStage] ?? $this->fromStage;
        $toLabel = Job::STAGES[$this->toStage] ?? $this->toStage;

        return [
            'job_id' => $this->job->id,
            'job_number' => $this->job->job_number,
            'title' => $this->job->title,
            'from_stage' => $this->fromStage,
            'to_stage' => $this->toStage,
            'message' => __('Job :number advanced from :from to :to', ['number' => $this->job->job_number, 'from' => $fromLabel, 'to' => $toLabel]),
        ];
    }
}
