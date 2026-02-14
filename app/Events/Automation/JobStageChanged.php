<?php

namespace App\Events\Automation;

use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class JobStageChanged extends AutomationEvent
{
    public function __construct(
        public Job $job,
        public string $oldStage,
        public string $newStage,
        public ?User $user = null,
    ) {}

    public function getEventName(): string
    {
        return match ($this->newStage) {
            'completed' => 'job.completed',
            default => 'job.stage_changed',
        };
    }

    public function getSubject(): Model
    {
        return $this->job;
    }

    public function getContext(): array
    {
        return [
            'old_stage' => $this->oldStage,
            'new_stage' => $this->newStage,
            'user_id' => $this->user?->id,
        ];
    }
}
