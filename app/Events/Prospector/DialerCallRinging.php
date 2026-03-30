<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Call;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DialerCallRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Call $call,
        public int $agentId,
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
        $this->call->load('contact');

        return [
            'call_ulid' => $this->call->ulid,
            'contact' => [
                'id' => $this->call->contact->id,
                'name' => $this->call->contact->getFullName(),
                'phone' => $this->call->contact->getDisplayPhone(),
            ],
            'from_number' => $this->call->from_number,
            'to_number' => $this->call->to_number,
        ];
    }

    public function broadcastAs(): string
    {
        return 'dialer.call.ringing';
    }
}
