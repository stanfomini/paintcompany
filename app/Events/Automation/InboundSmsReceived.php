<?php

namespace App\Events\Automation;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;

class InboundSmsReceived extends AutomationEvent
{
    public function __construct(
        public Customer $customer,
        public string $fromPhone,
        public string $messageBody,
    ) {}

    public function getEventName(): string
    {
        return 'sms.received';
    }

    public function getSubject(): Model
    {
        return $this->customer;
    }

    public function getContext(): array
    {
        return [
            'from_phone' => $this->fromPhone,
            'body' => $this->messageBody,
            'customer_id' => $this->customer->id,
        ];
    }
}
