<?php

namespace App\Events\Automation;

use App\Models\Applicant;
use Illuminate\Database\Eloquent\Model;

class ApplicationReceived extends AutomationEvent
{
    public function __construct(
        public Applicant $applicant,
    ) {}

    public function getEventName(): string
    {
        return 'application.received';
    }

    public function getSubject(): Model
    {
        return $this->applicant;
    }

    public function getContext(): array
    {
        return [
            'source' => $this->applicant->source ?? 'web',
            'job_listing_id' => $this->applicant->job_listing_id,
        ];
    }
}
