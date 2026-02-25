<?php

namespace App\Events;

use App\Models\EmailMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EmailMessage $message
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $account = $this->message->account;

        if (! $account) {
            return [];
        }

        // Personal account: single user channel
        if ($account->isPersonal() && $account->user_id) {
            return [new PrivateChannel('email.user.'.$account->user_id)];
        }

        // Shared account: broadcast to all members
        $channels = [];
        foreach ($account->members as $member) {
            $channels[] = new PrivateChannel('email.user.'.$member->id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'email.received';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'thread_id' => $this->message->email_thread_id,
            'from_address' => $this->message->from_address,
            'from_name' => $this->message->from_name,
            'subject' => $this->message->subject,
            'snippet' => $this->message->snippet,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
        ];
    }
}
