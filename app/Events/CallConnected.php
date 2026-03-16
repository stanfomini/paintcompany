<?php

namespace App\Events;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallConnected implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public CallLog $callLog,
        public int $answeredByUserId
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('call.user.'.$this->answeredByUserId),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->callLog->load(['customer', 'callerUser']);

        return [
            'call_log_id' => $this->callLog->id,
            'ulid' => $this->callLog->ulid,
            'from_number' => $this->callLog->from_number,
            'caller_name' => $this->callLog->customer?->name ?? $this->callLog->callerUser?->name,
            'customer_id' => $this->callLog->customer_id,
            'customer_name' => $this->callLog->customer?->name,
            'answered_at' => $this->callLog->answered_at?->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'call.connected';
    }
}
