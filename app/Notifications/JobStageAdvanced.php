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
            ->subject("Job Updated: {$this->job->job_number}")
            ->greeting("Job Updated: {$this->job->title}")
            ->line("Job {$this->job->job_number} has advanced from {$fromLabel} to {$toLabel}.")
            ->action('View Job', url('/jobs/'.$this->job->id));
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
            'message' => "Job {$this->job->job_number} advanced from {$fromLabel} to {$toLabel}",
        ];
    }
}
