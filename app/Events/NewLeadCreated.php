<?php

namespace App\Events;

use App\Models\Deal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewLeadCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Deal $deal
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        // Broadcast to internal users channel
        return [
            new PrivateChannel('internal-team'),
        ];
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->deal->load(['customer', 'property', 'createdBy']);

        return [
            'deal' => [
                'id' => $this->deal->id,
                'title' => $this->deal->title,
                'stage' => $this->deal->stage,
                'customer' => $this->deal->customer ? [
                    'id' => $this->deal->customer->id,
                    'full_name' => $this->deal->customer->full_name,
                    'email' => $this->deal->customer->email,
                ] : null,
                'property' => $this->deal->property ? [
                    'id' => $this->deal->property->id,
                    'address' => $this->deal->property->address,
                    'city' => $this->deal->property->city,
                ] : null,
                'created_by' => $this->deal->createdBy ? [
                    'id' => $this->deal->createdBy->id,
                    'name' => $this->deal->createdBy->name,
                ] : null,
                'created_at' => $this->deal->created_at->toISOString(),
            ],
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'lead.created';
    }
}
