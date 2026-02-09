<?php

namespace App\Events;

use App\Models\Job;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PhotoUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Job $job,
        public ?int $photoId = null,
        public string $action = 'updated',
        public ?int $actorId = null
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
            'photo_id' => $this->photoId,
            'action' => $this->action,
            'actor_id' => $this->actorId,
        ];
    }

    public function broadcastAs(): string
    {
        return 'photo.updated';
    }
}
