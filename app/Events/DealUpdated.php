<?php

namespace App\Events;

use App\Models\Deal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DealUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Deal $deal,
        public string $updatedBy = '',
        public string $changesSummary = '',
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('deal.'.$this->deal->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'deal_id' => $this->deal->id,
            'updated_by' => $this->updatedBy,
            'changes_summary' => $this->changesSummary,
        ];
    }

    public function broadcastAs(): string
    {
        return 'deal.updated';
    }
}
