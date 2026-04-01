<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboundCallRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Call $call,
        public Company $company,
        public string $conferenceName,
        public array $agentIds = [],
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('prospector.company.'.$this->company->id)];

        foreach ($this->agentIds as $agentId) {
            $channels[] = new PrivateChannel('prospector.agent.'.$agentId);
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->call->load('contact');

        return [
            'call_id' => $this->call->id,
            'call_ulid' => $this->call->ulid,
            'conference_name' => $this->conferenceName,
            'contact' => [
                'id' => $this->call->contact?->id,
                'name' => $this->call->contact?->getFullName() ?? 'Unknown',
                'phone' => $this->call->contact?->getDisplayPhone() ?? $this->call->from_number,
            ],
            'company_id' => $this->company->id,
            'company_name' => $this->company->name,
            'from_number' => $this->call->from_number,
            'to_number' => $this->call->to_number,
        ];
    }

    public function broadcastAs(): string
    {
        return 'inbound.call.ringing';
    }
}
