<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateVideoUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $estimateId,
        public int $recordingId,
        public string $status,
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
            'recording_id' => $this->recordingId,
            'status' => $this->status,
            'actor_name' => $this->actorName,
        ];
    }

    public function broadcastAs(): string
    {
        return 'estimate.video.updated';
    }
}
