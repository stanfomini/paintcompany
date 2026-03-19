<?php

namespace App\Events\Automation;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Model;

class DealCreated extends AutomationEvent
{
    public function __construct(public Deal $deal) {}

    public function getEventName(): string
    {
        return 'deal.created';
    }

    public function getSubject(): Model
    {
        return $this->deal;
    }

    public function getContext(): array
    {
        return [
            'customer_id' => $this->deal->customer_id,
            'stage' => $this->deal->stage,
            'has_preview' => $this->deal->projectPreview()->exists(),
        ];
    }
}
