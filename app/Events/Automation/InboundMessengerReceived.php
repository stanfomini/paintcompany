<?php

namespace App\Events\Automation;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class InboundMessengerReceived extends AutomationEvent
{
    public function __construct(
        public Customer $customer,
        public string $senderPsid,
        public string $messageText,
    ) {}

    public function getEventName(): string
    {
        return 'messenger.received';
    }

    public function getSubject(): Model
    {
        return $this->customer;
    }

    public function getContext(): array
    {
        return [
            'sender_psid' => $this->senderPsid,
            'message_text' => $this->messageText,
            'customer_id' => $this->customer->id,
        ];
    }
}
