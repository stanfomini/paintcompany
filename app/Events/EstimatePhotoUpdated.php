<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimatePhotoUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $estimateId,
        public int $photoId,
        public string $action,
        public string $actorName = '',
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('estimate.'.$this->estimateId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'estimate_id' => $this->estimateId,
            'photo_id' => $this->photoId,
            'action' => $this->action,
            'actor_name' => $this->actorName,
        ];
    }

    public function broadcastAs(): string
    {
        return 'estimate.photo.updated';
    }
}
