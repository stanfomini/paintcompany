<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobDispatchedToCrew implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Job $job,
        public int $crewUserId,
        public ?int $serviceCategoryId = null
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('crew.user.'.$this->crewUserId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'job_title' => $this->job->title,
            'job_number' => $this->job->job_number,
            'service_category_id' => $this->serviceCategoryId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'job.dispatched';
    }
}
