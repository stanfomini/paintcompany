<?php

namespace App\Events\Automation;

use App\Models\DoorKnock;
use Illuminate\Database\Eloquent\Model;

class DoorKnockRecorded extends AutomationEvent
{
    public function __construct(
        public DoorKnock $doorKnock,
    ) {}

    public function getEventName(): string
    {
        return 'doorknock.recorded';
    }

    public function getSubject(): Model
    {
        return $this->doorKnock;
    }

    public function getContext(): array
    {
        return [
            'doorknock_id' => $this->doorKnock->id,
            'status' => $this->doorKnock->status,
            'has_email' => ! empty($this->doorKnock->email),
            'has_phone' => ! empty($this->doorKnock->phone),
            'has_customer_name' => ! empty($this->doorKnock->customer_name),
        ];
    }
}
