<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Contact;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Contact $contact,
        public Campaign $campaign,
        public string $action = 'added', // added, removed, reordered, priority_bump
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('prospector.company.'.$this->campaign->company_id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'contact_id' => $this->contact->id,
            'contact_name' => $this->contact->getFullName(),
            'campaign_id' => $this->campaign->id,
            'campaign_name' => $this->campaign->name,
        ];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }
}
