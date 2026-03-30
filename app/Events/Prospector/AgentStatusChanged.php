<?php

namespace App\Events\Prospector;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $companyId,
        public int $agentId,
        public string $agentName,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('prospector.company.'.$this->companyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'agent_id' => $this->agentId,
            'agent_name' => $this->agentName,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.status.changed';
    }
}
