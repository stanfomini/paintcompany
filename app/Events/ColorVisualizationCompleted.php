<?php

namespace App\Events;

use App\Models\ColorVisualization;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ColorVisualizationCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ColorVisualization $visualization,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('visualization.'.$this->visualization->uuid),
        ];
    }

    public function broadcastAs(): string
    {
        return 'visualization.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->visualization->uuid,
            'status' => $this->visualization->status,
            'result_image_path' => $this->visualization->result_image_path,
        ];
    }
}
