<?php

namespace App\Events\Prospector;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $companyId,
        public string $action = 'updated', // updated, stage_changed, priority_bump
        public ?int $contactId = null,
        public ?array $contactData = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('prospector.company.'.$this->companyId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'contact_id' => $this->contactId,
            'contact' => $this->contactData,
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
