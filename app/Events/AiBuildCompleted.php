<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AiBuildCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $estimateId,
        public string $status,
        public int $areasCreated = 0,
        public int $surfacesCreated = 0,
        public ?string $summary = null,
        public ?string $errorMessage = null,
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

    public function broadcastAs(): string
    {
        return 'ai.build.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'estimate_id' => $this->estimateId,
            'status' => $this->status,
            'areas_created' => $this->areasCreated,
            'surfaces_created' => $this->surfacesCreated,
            'summary' => $this->summary,
            'error_message' => $this->errorMessage,
        ];
    }
}
