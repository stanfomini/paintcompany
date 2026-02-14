<?php

namespace App\Events\Automation;

use App\Models\AutomationWebhook;
use Illuminate\Database\Eloquent\Model;

class WebhookReceived extends AutomationEvent
{
    public function __construct(
        public AutomationWebhook $webhook,
        public array $payload = [],
    ) {}

    public function getEventName(): string
    {
        return 'webhook.received';
    }

    public function getSubject(): Model
    {
        return $this->webhook;
    }

    public function getContext(): array
    {
        return [
            'webhook_id' => $this->webhook->id,
            'webhook_token' => $this->webhook->token,
            'payload' => $this->payload,
        ];
    }
}
