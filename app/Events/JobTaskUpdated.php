<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobTaskUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Job $job,
        public int $taskId,
        public string $status,
        public int $dayNumber = 1,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('job.'.$this->job->id),
            new PrivateChannel('crew.job.'.$this->job->id),
            new PrivateChannel('customer.job.'.$this->job->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'task_id' => $this->taskId,
            'status' => $this->status,
            'day_number' => $this->dayNumber,
        ];
    }

    public function broadcastAs(): string
    {
        return 'task.updated';
    }
}
