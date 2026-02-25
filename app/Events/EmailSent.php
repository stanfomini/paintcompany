<?php

namespace App\Events;

use App\Models\EmailMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EmailSent implements ShouldBroadcast
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
        return 'email.sent';
    }

    public function broadcastConnection(): string
    {
        return 'redis';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'thread_id' => $this->message->email_thread_id,
            'subject' => $this->message->subject,
            'sent_at' => $this->message->sent_at?->toIso8601String(),
        ];
    }
}
