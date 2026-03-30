<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DialerCallEnded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Call $call,
        public int $agentId,
        public bool $hasNextContact = false,
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
        return [
            'call_ulid' => $this->call->ulid,
            'disposition' => $this->call->disposition,
            'duration_seconds' => $this->call->duration_seconds,
            'has_next_contact' => $this->hasNextContact,
        ];
    }

    public function broadcastAs(): string
    {
        return 'dialer.call.ended';
    }
}
