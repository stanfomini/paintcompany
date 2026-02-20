<?php

namespace App\Events;

use App\Models\TerritoryOffer;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TerritoryOffered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public TerritoryOffer $offer,
        public int $userId,
        public int $queuePosition,
        public int $totalEligible
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('territory-dispatch.user.'.$this->userId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'offer_id' => $this->offer->id,
            'territory' => [
                'id' => $this->offer->territory_id,
                'name' => $this->offer->territory->name,
                'color' => $this->offer->territory->color,
                'center_lat' => $this->offer->territory->center_lat,
                'center_lng' => $this->offer->territory->center_lng,
            ],
            'work_date' => $this->offer->work_date->toDateString(),
            'expires_at' => $this->offer->expires_at->toIso8601String(),
            'queue_position' => $this->queuePosition,
            'total_eligible' => $this->totalEligible,
        ];
    }

    public function broadcastAs(): string
    {
        return 'territory.offered';
    }
}
