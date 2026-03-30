<?php

namespace App\Events\Prospector;

use App\Models\Prospector\CallIntelligence;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallIntelligenceReady implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CallIntelligence $intelligence,
        public int $agentId,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('prospector.agent.'.$this->agentId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->intelligence->call_id,
            'lead_score' => $this->intelligence->lead_score,
            'ai_summary' => $this->intelligence->ai_summary,
        ];
    }

    public function broadcastAs(): string
    {
        return 'call.intelligence.ready';
    }
}
