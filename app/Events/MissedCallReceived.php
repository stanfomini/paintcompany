<?php

namespace App\Events;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MissedCallReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CallLog $callLog
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * Missed calls (no-answer) are always company-wide, so notify users with
     * phone.receive_calls, phone.view_company_voicemails, or phone.view_all_voicemails.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $permissions = ['phone.receive_calls', 'phone.view_company_voicemails', 'phone.view_all_voicemails'];

        $userIds = collect();

        foreach ($permissions as $permission) {
            $ids = User::query()
                ->where('type', 'internal')
                ->permission($permission)
                ->pluck('id');

            $userIds = $userIds->merge($ids);
        }

        return $userIds->unique()
            ->map(fn (int $userId) => new PrivateChannel('call.user.'.$userId))
            ->values()
            ->all();
    }

    /**
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
            'status' => $this->callLog->status,
        ];
    }

    public function broadcastAs(): string
    {
        return 'missed_call.received';
    }
}
