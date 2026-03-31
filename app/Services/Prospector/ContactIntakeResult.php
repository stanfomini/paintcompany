<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Contact;

class ContactIntakeResult
{
    public function __construct(
        public Contact $contact,
        public bool $wasCreated,
    ) {}
}
