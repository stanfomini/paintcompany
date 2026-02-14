<?php

namespace App\Events\Automation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ContactCreated extends AutomationEvent
{
    public function __construct(
        public User $contact,
        public string $userType,
    ) {}

    public function getEventName(): string
    {
        return 'contact.created';
    }

    public function getSubject(): Model
    {
        return $this->contact;
    }

    public function getContext(): array
    {
        return [
            'user_type' => $this->userType,
        ];
    }
}
