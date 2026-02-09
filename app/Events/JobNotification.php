<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        public Job $job,
        public string $message,
        public string $type = 'info',
        public array $channels = []
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        if (! empty($this->channels)) {
            return array_map(
                fn (string $channel) => new PrivateChannel($channel),
                $this->channels
            );
        }

        return [
            new PrivateChannel('job.'.$this->job->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'job_id' => $this->job->id,
            'message' => $this->message,
            'type' => $this->type,
        ];
    }

    public function broadcastAs(): string
    {
        return 'job.notification';
    }
}
