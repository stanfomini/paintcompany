<?php

namespace App\Events\Automation;

use App\Models\ExternalApiSource;
use Illuminate\Database\Eloquent\Model;

class ApiPollReceived extends AutomationEvent
{
    public function __construct(
        public ExternalApiSource $source,
        public array $payload = [],
    ) {}

    public function getEventName(): string
    {
        return 'api_poll.received';
    }

    public function getSubject(): Model
    {
        return $this->source;
    }

    public function getContext(): array
    {
        return [
            'api_source_id' => $this->source->id,
            'api_source_token' => $this->source->token,
            'payload' => $this->payload,
        ];
    }
}
