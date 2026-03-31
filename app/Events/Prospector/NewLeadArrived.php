<?php

namespace App\Events\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewLeadArrived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Contact $contact,
        public Company $company,
        public ?Campaign $campaign = null,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('prospector.company.'.$this->company->id)];

        $agents = Agent::where('company_id', $this->company->id)->online()->get();
        foreach ($agents as $agent) {
            $channels[] = new PrivateChannel('prospector.agent.'.$agent->id);
        }

        return $channels;
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
            'company_id' => $this->company->id,
            'company_name' => $this->company->name,
            'campaign_id' => $this->campaign?->id,
            'campaign_name' => $this->campaign?->name,
            'source' => $this->contact->source,
        ];
    }

    public function broadcastAs(): string
    {
        return 'lead.arrived';
    }
}
