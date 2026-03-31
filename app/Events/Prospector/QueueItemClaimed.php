<?php

namespace App\Events\Prospector;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class QueueItemClaimed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $contactId,
        public int $claimedByAgentId,
        public int $companyId,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('prospector.company.'.$this->companyId)];
    }

    public function broadcastAs(): string
    {
        return 'queue.item.claimed';
    }
}
