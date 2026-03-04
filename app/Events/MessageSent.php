<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public ChatMessage $message
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('chat.thread.'.$this->message->thread_id),
        ];

        // Also broadcast to each participant's user channel for badge/count updates
        $this->message->load('thread.activeParticipants');
        foreach ($this->message->thread->activeParticipants as $participant) {
            if ($participant->user_id !== $this->message->user_id) {
                $channels[] = new PrivateChannel('chat.user.'.$participant->user_id);
            }
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
        $this->message->load(['user', 'attachments']);

        $userData = null;
        if ($this->message->user) {
            $userData = [
                'id' => $this->message->user->id,
                'name' => $this->message->user->name,
                'type' => $this->message->user->type,
            ];
        } elseif ($this->message->metadata) {
            // Guest message - use metadata for display
            $userData = [
                'id' => null,
                'name' => $this->message->metadata['guest_name'] ?? $this->message->metadata['guest_email'] ?? 'Guest',
                'type' => 'guest',
            ];
        }

        return [
            'message' => [
                'id' => $this->message->id,
                'thread_id' => $this->message->thread_id,
                'user_id' => $this->message->user_id,
                'content' => $this->message->content,
                'type' => $this->message->type,
                'is_internal' => $this->message->is_internal,
                'parent_id' => $this->message->parent_id,
                'metadata' => $this->message->metadata,
                'created_at' => $this->message->created_at->toISOString(),
                'user' => $userData,
                'attachments' => $this->message->attachments->map(fn ($attachment) => [
                    'id' => $attachment->id,
                    'filename' => $attachment->filename,
                    'original_filename' => $attachment->original_filename,
                    'mime_type' => $attachment->mime_type,
                    'size' => $attachment->size,
                ])->toArray(),
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'message.sent';
    }
}
