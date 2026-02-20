<?php

namespace App\Events;

use App\Models\TerritoryOffer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TerritoryClaimed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public TerritoryOffer $offer
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('territory-dispatch.offer.'.$this->offer->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'offer_id' => $this->offer->id,
            'claimed_by' => $this->offer->claimedByUser->name ?? 'Unknown',
            'territory_name' => $this->offer->territory->name,
        ];
    }

    public function broadcastAs(): string
    {
        return 'territory.claimed';
    }
}
