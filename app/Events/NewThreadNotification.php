<?php

namespace App\Events;

use App\Models\ChatThread;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewThreadNotification implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ChatThread $thread
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $this->thread->load('activeParticipants');

        $channels = [];

        foreach ($this->thread->activeParticipants as $participant) {
            $channels[] = new PrivateChannel('chat.user.'.$participant->user_id);
        }

        return $channels;
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->thread->load(['activeParticipants.user', 'deal']);

        return [
            'thread' => [
                'id' => $this->thread->id,
                'type' => $this->thread->type,
                'subject' => $this->thread->subject,
                'deal_id' => $this->thread->deal_id,
                'deal' => $this->thread->deal ? [
                    'id' => $this->thread->deal->id,
                    'title' => $this->thread->deal->title,
                ] : null,
                'created_at' => $this->thread->created_at->toISOString(),
                'participants' => $this->thread->activeParticipants->map(fn ($participant) => [
                    'user_id' => $participant->user_id,
                    'user' => [
                        'id' => $participant->user->id,
                        'name' => $participant->user->name,
                        'type' => $participant->user->type,
                    ],
                ])->toArray(),
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'thread.created';
    }
}
