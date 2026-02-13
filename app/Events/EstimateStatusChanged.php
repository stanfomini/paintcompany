<?php

namespace App\Events;

use App\Models\Estimate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Estimate $estimate,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('estimate.'.$this->estimate->id),
        ];

        if ($this->estimate->deal_id) {
            $channels[] = new PrivateChannel('deal.'.$this->estimate->deal_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'estimate.status.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'estimate_id' => $this->estimate->id,
            'estimate_number' => $this->estimate->estimate_number,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
