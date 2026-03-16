<?php

namespace App\Events;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoicemailReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public CallLog $callLog
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return User::query()
            ->where('type', 'internal')
            ->permission('phone.receive_calls')
            ->pluck('id')
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
        $this->callLog->load('customer');

        return [
            'call_log_id' => $this->callLog->id,
            'ulid' => $this->callLog->ulid,
            'from_number' => $this->callLog->from_number,
            'caller_name' => $this->callLog->customer?->name,
            'customer_id' => $this->callLog->customer_id,
            'customer_name' => $this->callLog->customer?->name,
            'recording_url' => $this->callLog->recording_url,
            'voicemail_transcription' => $this->callLog->voicemail_transcription,
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'voicemail.received';
    }
}
