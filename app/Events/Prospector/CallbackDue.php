<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Contact;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallbackDue implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Contact $contact,
        public int $campaignId,
        public int $companyId,
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
            'contact_id' => $this->contact->id,
            'contact_name' => $this->contact->getFullName(),
            'contact_phone' => $this->contact->getDisplayPhone(),
            'campaign_id' => $this->campaignId,
            'type' => 'callback_due',
        ];
    }

    public function broadcastAs(): string
    {
        return 'callback.due';
    }
}
