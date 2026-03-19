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
     * Personal voicemail (voicemail_for_user_id set):
     *   - The specific user it's for
     *   - Users with phone.view_all_voicemails permission
     *
     * Company voicemail (voicemail_for_user_id null):
     *   - Users with phone.view_company_voicemails permission
     *   - Users with phone.view_all_voicemails permission
     *   - Users with phone.receive_calls permission
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $userIds = collect();

        if ($this->callLog->isForUser()) {
            // Personal voicemail: notify the target user + view_all users
            $userIds->push($this->callLog->voicemail_for_user_id);

            $viewAllIds = User::query()
                ->where('type', 'internal')
                ->permission('phone.view_all_voicemails')
                ->pluck('id');

            $userIds = $userIds->merge($viewAllIds);
        } else {
            // Company-wide voicemail: notify view_company + view_all + receive_calls users
            $permissions = ['phone.view_company_voicemails', 'phone.view_all_voicemails', 'phone.receive_calls'];

            foreach ($permissions as $permission) {
                $ids = User::query()
                    ->where('type', 'internal')
                    ->permission($permission)
                    ->pluck('id');

                $userIds = $userIds->merge($ids);
            }
        }

        return $userIds->unique()
            ->map(fn (int $userId) => new PrivateChannel('call.user.'.$userId))
            ->values()
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
            'voicemail_for_user_id' => $this->callLog->voicemail_for_user_id,
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
