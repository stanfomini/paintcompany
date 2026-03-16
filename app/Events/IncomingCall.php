<?php

namespace App\Events;

use App\Models\CallLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncomingCall implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  array<int>  $targetUserIds
     */
    public function __construct(
        public CallLog $callLog,
        public array $targetUserIds
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return collect($this->targetUserIds)
            ->map(fn (int $userId) => new PrivateChannel('call.user.'.$userId))
            ->all();
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
            'started_at' => $this->callLog->started_at?->toISOString(),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'call.incoming';
    }
}
