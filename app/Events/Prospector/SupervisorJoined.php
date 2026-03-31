<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Agent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupervisorJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Agent $supervisor,
        public int $agentId,
        public string $mode,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('prospector.agent.'.$this->agentId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->supervisor->load('user');

        return [
            'supervisor_name' => $this->supervisor->user?->name ?? 'Manager',
            'mode' => $this->mode,
        ];
    }

    public function broadcastAs(): string
    {
        return 'supervisor.joined';
    }
}
