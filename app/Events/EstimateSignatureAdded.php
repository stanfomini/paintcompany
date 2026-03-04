<?php

namespace App\Events;

use App\Models\Estimate;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EstimateSignatureAdded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Estimate $estimate,
        public string $signerName,
        public string $signedAt,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('estimate.'.$this->estimate->id),
        ];

        if ($this->estimate->deal_id) {
            $channels[] = new PrivateChannel('deal.'.$this->estimate->deal_id);
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'estimate_id' => $this->estimate->id,
            'signer_name' => $this->signerName,
            'signed_at' => $this->signedAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'estimate.signature.added';
    }
}
