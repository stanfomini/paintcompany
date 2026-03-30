<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Contact;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContactDispositioned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $companyId,
        public Contact $contact,
        public string $disposition,
        public string $agentName,
        public ?string $campaignName = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('prospector.company.'.$this->companyId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'contact' => [
                'id' => $this->contact->id,
                'name' => $this->contact->getFullName(),
                'phone' => $this->contact->getDisplayPhone(),
            ],
            'disposition' => $this->disposition,
            'agent_name' => $this->agentName,
            'campaign_name' => $this->campaignName,
        ];
    }

    public function broadcastAs(): string
    {
        return 'contact.dispositioned';
    }
}
