<?php

namespace App\Exceptions;

use RuntimeException;

class SlotUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'The selected time slot is no longer available.')
    {
        parent::__construct($message);
    }
}
