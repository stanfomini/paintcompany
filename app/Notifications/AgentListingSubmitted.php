<?php

namespace App\Notifications;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AgentListingSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Deal $deal,
        public User $agent,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->deal->load('property');

        $address = $this->deal->property?->full_address ?? $this->deal->title;
        $agentName = $this->agent->name;
        $intent = $this->deal->agent_intent === 'selling' ? __('selling') : __('buying');
        $value = $this->deal->listing_value ? '$'.number_format($this->deal->listing_value, 0) : __('Not specified');

        $message = (new MailMessage)
            ->subject(__('New Agent Listing: :address', ['address' => $address]))
            ->greeting(__('New listing from an agent!'))
            ->line(__('**:name** has submitted a new listing that needs a paint estimate.', ['name' => $agentName]))
            ->line('**'.__('Property').':** '.$address)
            ->line('**'.__('Intent').':** '.__('The agent is helping a client who is :intent.', ['intent' => $intent]))
            ->line('**'.__('Listing Value').':** '.$value);

        if ($this->deal->listing_bedrooms) {
            $message->line('**'.__('Bedrooms').':** '.$this->deal->listing_bedrooms);
        }

        if ($this->deal->listing_sqft) {
            $message->line('**'.__('Sq Ft').':** '.number_format($this->deal->listing_sqft));
        }

        $message->action(__('View Deal'), route('deals.show', $this->deal))
            ->line(__('Please prepare an estimate for this listing.'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'deal_id' => $this->deal->id,
            'agent_id' => $this->agent->id,
            'agent_name' => $this->agent->name,
            'property_address' => $this->deal->property?->full_address ?? $this->deal->title,
            'intent' => $this->deal->agent_intent,
        ];
    }
}
