<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Job $job
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('internal-jobs'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'job_number' => $this->job->job_number ?? null,
            'deal_id' => $this->job->deal_id,
            'customer_name' => $this->job->deal?->customer?->user?->name ?? '',
        ];
    }

    public function broadcastAs(): string
    {
        return 'job.created';
    }
}
