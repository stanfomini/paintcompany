<?php

namespace App\Events\Automation;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;

class TaskStatusChanged extends AutomationEvent
{
    public function __construct(
        public Activity $activity,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    public function getEventName(): string
    {
        return match ($this->newStatus) {
            'completed' => 'task.completed',
            default => 'task.status_changed',
        };
    }

    public function getSubject(): Model
    {
        return $this->activity;
    }

    public function getContext(): array
    {
        return [
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
